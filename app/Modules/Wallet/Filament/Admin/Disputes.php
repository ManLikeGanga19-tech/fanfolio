<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Filament\Admin;

use App\Modules\Identity\Contracts\AccountDirectory;
use App\Modules\Identity\Contracts\Accounts;
use App\Modules\Identity\Contracts\StaffAbility;
use App\Modules\Wallet\Actions\DisputeEvidence;
use App\Modules\Wallet\Actions\RefundPurchase;
use App\Modules\Wallet\Actions\RespondToDispute;
use App\Modules\Wallet\Contracts\MoneyStats;
use App\Modules\Wallet\Enums\DisputeStatus;
use App\Modules\Wallet\Exceptions\WalletException;
use App\Modules\Wallet\Models\Dispute;
use App\Modules\Wallet\Models\TopUp;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;

/**
 * Canvas board AdminDisputes (plan M8a use case 37, M3 PR 6, D-20). Chargebacks arrive
 * from the processor with the money already taken back from the fan's credits and the
 * sales they paid for; the fan's wallet is frozen. Admins answer before the network's
 * deadline: Submit evidence (gathered from our logs) or Accept & refund, and once no
 * dispute of the fan is open, lift the freeze. Below the board, an admin can refund a
 * single sale to a fan's credits (spec postings "Refund (admin)").
 */
final class Disputes extends Page
{
    private const int PER_PAGE = 25;

    /** Respond-by dates this close are shown as urgent. */
    public const int URGENT_HOURS = 48;

    /** Spec alert threshold (warn above 0.75% a month) and where processors act. */
    public const int WARN_BPS = 75;

    public const int DANGER_BPS = 100;

    protected static string|BackedEnum|null $navigationIcon = null;

    protected static ?string $navigationLabel = 'Disputes';

    protected static ?int $navigationSort = 5;

    protected static ?string $title = 'Disputes & chargebacks';

    protected static ?string $slug = 'disputes';

    protected string $view = 'wallet::admin.disputes';

    #[Url(as: 'case')]
    public ?string $selected = null;

    #[Url(except: 'open')]
    public string $tab = 'open';

    #[Url(except: 1)]
    public int $page = 1;

    #[Url(as: 'fan', except: '')]
    public string $refundFan = '';

    public static function canAccess(): bool
    {
        return Gate::allows(StaffAbility::HandleDisputes->value);
    }

    public function getSubheading(): string
    {
        return 'Answer before the card network deadline. Evidence is gathered automatically from logs.';
    }

    public function select(string $disputeId): void
    {
        $this->selected = $disputeId;
        $this->resetErrorBag();
    }

    public function showTab(string $tab): void
    {
        $this->tab = $tab === 'decided' ? 'decided' : 'open';
        $this->selected = null;
        $this->page = 1;
    }

    public function goToPage(int $page): void
    {
        $this->page = max(1, $page);
    }

    /** "0.42%" from basis points, integers only. */
    public static function percent(int $bps): string
    {
        return intdiv($bps, 100).'.'.str_pad((string) ($bps % 100), 2, '0', STR_PAD_LEFT).'%';
    }

    /** @return 'ok'|'warning'|'danger' */
    public static function rateTone(int $bps): string
    {
        return $bps >= self::DANGER_BPS ? 'danger' : ($bps >= self::WARN_BPS ? 'warning' : 'ok');
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $queue = $this->tab === 'decided'
            ? Dispute::query()->whereNotIn('status', DisputeStatus::UNDECIDED)->orderByDesc('decided_at')->orderByDesc('id')
            : Dispute::query()->whereIn('status', DisputeStatus::UNDECIDED)->orderBy('respond_by')->orderBy('id');
        $queue = $queue->paginate(self::PER_PAGE, page: $this->page);

        $detail = $this->detail() ?? $queue->getCollection()->first();
        if ($detail instanceof Dispute && $this->selected !== $detail->id) {
            $this->selected = $detail->id;
        }

        $accounts = app(Accounts::class);
        $names = [];
        foreach ([...$queue->items(), ...($detail instanceof Dispute ? [$detail] : [])] as $dispute) {
            $names[$dispute->fan_id] ??= $accounts->username($dispute->fan_id);
        }

        $frozen = $detail instanceof Dispute && DB::table('wallet_freezes')->where('fan_id', $detail->fan_id)->whereNull('lifted_at')->exists();

        return [
            'tiles' => $this->tiles(),
            'queue' => $queue,
            'detail' => $detail,
            'topUp' => $detail instanceof Dispute ? TopUp::query()->find($detail->topup_id) : null,
            'names' => $names,
            'evidence' => $detail instanceof Dispute ? $this->evidence($detail) : [],
            'frozen' => $frozen,
            'canLift' => $frozen && ! Dispute::query()->where('fan_id', $detail->fan_id)->whereIn('status', DisputeStatus::UNDECIDED)->exists(),
            'refund' => $this->refundSearch(),
            'canRefund' => Gate::allows(StaffAbility::RefundPurchase->value),
        ];
    }

    public function submitEvidenceAction(): Action
    {
        return Action::make('submitEvidence')
            ->label('Submit evidence')
            ->color('primary')
            ->requiresConfirmation()
            ->modalHeading('Send this evidence to the processor?')
            ->modalDescription('The lines listed on the case go to the card network. Its decision comes back in a few weeks.')
            ->modalSubmitActionLabel('Submit evidence')
            ->action(function (): void {
                $this->run(fn (RespondToDispute $respond, string $staff): mixed => $respond->submitEvidence((string) $this->selected, $staff), 'Evidence sent. The bank will decide.');
            });
    }

    public function acceptAction(): Action
    {
        return Action::make('accept')
            ->label('Accept & refund')
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading('Accept this chargeback?')
            ->modalDescription('We stop contesting it and the card holder keeps the refund. The credits and sales were already taken back when it arrived. The wallet stays frozen until you lift it.')
            ->schema([$this->noteField()])
            ->modalSubmitActionLabel('Accept & refund')
            ->action(function (array $data): void {
                $this->run(fn (RespondToDispute $respond, string $staff): mixed => $respond->accept((string) $this->selected, $staff, $this->noteFrom($data)), 'Accepted. The card holder keeps the refund.');
            });
    }

    public function liftFreezeAction(): Action
    {
        return Action::make('liftFreeze')
            ->label('Lift wallet freeze')
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading('Give this fan their wallet back?')
            ->modalDescription('They can top up and spend their credits again.')
            ->schema([$this->noteField()])
            ->modalSubmitActionLabel('Lift freeze')
            ->action(function (array $data): void {
                $fan = $this->detail()->fan_id ?? '';
                $this->run(function (RespondToDispute $respond, string $staff) use ($fan, $data): mixed {
                    $respond->liftFreeze($fan, $staff, $this->noteFrom($data));

                    return null;
                }, 'Freeze lifted. The fan can use their wallet again.');
            });
    }

    public function refundSaleAction(): Action
    {
        return Action::make('refundSale')
            ->label('Refund')
            ->color('gray')
            ->size('sm')
            ->requiresConfirmation()
            ->modalHeading('Refund this sale to the fan\'s credits?')
            ->modalDescription('The credits go back to the fan\'s wallet; the creator\'s share and the fee are taken back. Credits are never paid out to cards.')
            ->schema([
                Textarea::make('reason')
                    ->label('Reason (saved to the audit log)')
                    ->rows(3)
                    ->required()
                    ->minLength(3)
                    ->maxLength(500)
                    ->validationMessages(['required' => 'Write the reason for the refund.', 'min' => 'Write the reason for the refund.']),
            ])
            ->modalSubmitActionLabel('Refund')
            ->action(function (array $data, array $arguments): void {
                $sale = is_string($arguments['sale'] ?? null) ? $arguments['sale'] : '';
                $reason = is_string($data['reason'] ?? null) ? $data['reason'] : '';

                $this->run(function (RespondToDispute $respond, string $staff) use ($sale, $reason): mixed {
                    app(RefundPurchase::class)->handle($sale, $staff, $reason);

                    return null;
                }, 'Refunded to the fan\'s credits.');
            });
    }

    /**
     * Canvas tiles: chargeback rate (30 days), open with those due soon, won in the last
     * 90 days (of those decided by the network), fans with a frozen wallet.
     *
     * @return array{rate: array{bps: int, chargebacks: int, topups: int}, open: int, urgent: int, won: int, decided: int, frozen: int}
     */
    private function tiles(): array
    {
        $open = Dispute::query()->whereIn('status', DisputeStatus::UNDECIDED);
        $decided = Dispute::query()->whereIn('status', [DisputeStatus::Won, DisputeStatus::Lost])->where('decided_at', '>=', now()->subDays(90));

        return [
            'rate' => app(MoneyStats::class)->chargebackRate(CarbonImmutable::now()->subDays(30)),
            'open' => (clone $open)->count(),
            'urgent' => $open->where('respond_by', '<=', now()->addHours(self::URGENT_HOURS))->count(),
            'won' => (clone $decided)->where('status', DisputeStatus::Won)->count(),
            'decided' => $decided->count(),
            'frozen' => DB::table('wallet_freezes')->whereNull('lifted_at')->count(),
        ];
    }

    /**
     * While open, the evidence as it would be sent now; afterwards, what was sent.
     *
     * @return list<array{text: string, tone: 'ok'|'warning'|'danger'|'muted'}>
     */
    private function evidence(Dispute $dispute): array
    {
        if ($dispute->status === DisputeStatus::Open) {
            return app(DisputeEvidence::class)->lines($dispute);
        }

        return array_map(static fn (string $line): array => ['text' => $line, 'tone' => 'ok'], $dispute->evidence ?? []);
    }

    /**
     * "Refund a sale": the fan's last sales, found by username.
     *
     * @return array{searched: bool, fan: string|null, username: string|null, sales: list<object{transaction_id: string, creator_id: string, kind: string, gross_minor: int|string, created_at: string, released_at: string|null, refunded_at: string|null, dispute_id: string|null}>, creatorNames: array<string, string|null>}
     */
    private function refundSearch(): array
    {
        $term = ltrim(trim($this->refundFan), '@');
        $result = ['searched' => $term !== '', 'fan' => null, 'username' => null, 'sales' => [], 'creatorNames' => []];

        if ($term === '' || ! Gate::allows(StaffAbility::RefundPurchase->value)) {
            return $result;
        }

        $fan = app(AccountDirectory::class)->findByUsername($term);
        if ($fan === null) {
            return $result;
        }

        /** @var list<object{transaction_id: string, creator_id: string, kind: string, gross_minor: int|string, created_at: string, released_at: string|null, refunded_at: string|null, dispute_id: string|null}> $sales */
        $sales = DB::table('earning_holds')
            ->where('fan_id', $fan->id)
            ->orderByDesc('created_at')
            ->limit(20)
            ->get(['transaction_id', 'creator_id', 'kind', 'gross_minor', 'created_at', 'released_at', 'refunded_at', 'dispute_id'])
            ->all();

        $accounts = app(Accounts::class);
        $creators = [];
        foreach ($sales as $sale) {
            $creators[$sale->creator_id] ??= $accounts->username($sale->creator_id);
        }

        return ['searched' => true, 'fan' => $fan->id, 'username' => $fan->username, 'sales' => $sales, 'creatorNames' => $creators];
    }

    /**
     * @param  LengthAwarePaginator<int, Dispute>  $queue
     */
    public static function pageLabel(LengthAwarePaginator $queue): string
    {
        return 'Page '.$queue->currentPage().' of '.max(1, $queue->lastPage());
    }

    private function noteField(): Textarea
    {
        return Textarea::make('note')
            ->label('Note (saved to the audit log)')
            ->helperText('The fan never sees it.')
            ->rows(3)
            ->required()
            ->minLength(3)
            ->maxLength(500)
            ->validationMessages(['required' => 'Write a note for the audit log.', 'min' => 'Write a note for the audit log.']);
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private function noteFrom(array $data): string
    {
        return is_string($data['note'] ?? null) ? $data['note'] : '';
    }

    private function detail(): ?Dispute
    {
        return $this->selected === null ? null : Dispute::query()->find($this->selected);
    }

    /**
     * @param  callable(RespondToDispute, string): mixed  $change
     */
    private function run(callable $change, string $done): void
    {
        $staff = auth()->id();
        abort_unless(is_string($staff), 403);

        $this->resetErrorBag();

        try {
            $change(app(RespondToDispute::class), $staff);
        } catch (WalletException $e) {
            $this->addError($e->field, $e->getMessage());
            Notification::make()->title($e->getMessage())->danger()->send();

            return;
        }

        Notification::make()->title($done)->success()->send();
    }
}
