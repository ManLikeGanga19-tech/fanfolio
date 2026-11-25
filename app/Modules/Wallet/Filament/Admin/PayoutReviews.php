<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Filament\Admin;

use App\Modules\Identity\Contracts\Accounts;
use App\Modules\Identity\Contracts\StaffAbility;
use App\Modules\Wallet\Actions\LedgerIntegrity;
use App\Modules\Wallet\Actions\ReviewPayout;
use App\Modules\Wallet\Contracts\Data\AccountRef;
use App\Modules\Wallet\Contracts\Data\AccountType;
use App\Modules\Wallet\Contracts\Ledger;
use App\Modules\Wallet\Contracts\MoneyStats;
use App\Modules\Wallet\Enums\PayoutState;
use App\Modules\Wallet\Exceptions\WalletException;
use App\Modules\Wallet\Models\Payout;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;

/**
 * Canvas board AdminPayouts (plan M8a use case 36, M3 PR 5): withdrawals over $1,000 or
 * with a risk flag wait here, oldest first. The detail lists the checks behind the
 * request; an admin approves and sends, holds (asks the creator for more information,
 * funds stay locked) or declines (the money returns to the creator's earnings), all
 * through ReviewPayout, audited.
 */
final class PayoutReviews extends Page
{
    private const int PER_PAGE = 25;

    protected static string|BackedEnum|null $navigationIcon = null;

    protected static ?string $navigationLabel = 'Payout reviews';

    protected static ?int $navigationSort = 4;

    protected static ?string $title = 'Payout reviews';

    protected static ?string $slug = 'payouts';

    protected string $view = 'wallet::admin.payouts';

    #[Url(as: 'payout')]
    public ?string $selected = null;

    #[Url(except: 1)]
    public int $page = 1;

    public static function canAccess(): bool
    {
        return Gate::allows(StaffAbility::ReviewPayouts->value);
    }

    public static function getNavigationBadge(): ?string
    {
        $waiting = app(MoneyStats::class)->payoutsWaiting()['count'];

        return $waiting > 0 ? (string) $waiting : null;
    }

    public static function getNavigationBadgeTooltip(): string
    {
        return 'Waiting for review';
    }

    public function getSubheading(): string
    {
        return 'Requests of $1,000 or less with no risk flags are paid automatically. These need a person.';
    }

    public function select(string $payoutId): void
    {
        $this->selected = $payoutId;
        $this->resetErrorBag();
    }

    public function goToPage(int $page): void
    {
        $this->page = max(1, $page);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $queue = Payout::query()
            ->whereIn('status', [PayoutState::Review, PayoutState::Held])
            ->orderBy('requested_at')
            ->orderBy('id')
            ->paginate(self::PER_PAGE, page: $this->page);

        $detail = $this->detail() ?? $queue->getCollection()->first();
        if ($detail instanceof Payout && $this->selected !== $detail->id) {
            $this->selected = $detail->id;
        }

        $accounts = app(Accounts::class);
        $names = [];
        foreach ([...$queue->items(), ...($detail instanceof Payout ? [$detail] : [])] as $payout) {
            $names[$payout->creator_id] ??= $accounts->username($payout->creator_id);
        }

        return [
            'tiles' => $this->tiles(),
            'queue' => $queue,
            'detail' => $detail,
            'names' => $names,
            'checks' => $detail instanceof Payout ? $this->checks($detail) : [],
            'reviewer' => $detail?->reviewed_by !== null ? $accounts->username($detail->reviewed_by) : null,
            'waiting' => $detail instanceof Payout && in_array($detail->status, [PayoutState::Review, PayoutState::Held], true),
        ];
    }

    /** "$4,200", or "$12.50" when there are cents. */
    public static function money(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);

        return $sign.'$'.number_format(intdiv($cents, 100)).($cents % 100 === 0 ? '' : '.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT));
    }

    /**
     * The canvas pill for each flag: danger for a name mismatch, warning for the rest.
     *
     * @return list<array{text: string, tone: 'danger'|'warning'|'muted'}>
     */
    public static function flags(Payout $payout): array
    {
        $pills = [];
        foreach ($payout->flags as $flag) {
            $pills[] = [
                'text' => Payout::FLAG_LABELS[$flag] ?? $flag,
                'tone' => match ($flag) {
                    Payout::NAME_MISMATCH => 'danger',
                    Payout::OVER_REVIEW_LIMIT => 'muted',
                    default => 'warning',
                },
            ];
        }

        if ($payout->status === PayoutState::Held) {
            $pills[] = ['text' => 'On hold', 'tone' => 'muted'];
        }

        return $pills;
    }

    /**
     * @param  LengthAwarePaginator<int, Payout>  $queue
     */
    public static function pageLabel(LengthAwarePaginator $queue): string
    {
        return 'Page '.$queue->currentPage().' of '.max(1, $queue->lastPage());
    }

    public function approveAction(): Action
    {
        return Action::make('approve')
            ->label('Approve & send')
            ->color('primary')
            ->requiresConfirmation()
            ->modalHeading(fn (): string => 'Approve and send '.self::money($this->detail()->amount_minor ?? 0).'?')
            ->modalDescription('It goes to the payout provider straight away. The creator gets an email when it is sent.')
            ->schema([$this->noteField(required: false)])
            ->modalSubmitActionLabel('Approve & send')
            ->action(function (array $data): void {
                $this->decide(fn (ReviewPayout $review, string $staff): Payout => $review->approve((string) $this->selected, $staff, $this->noteFrom($data)), 'Approved. Sending now.');
            });
    }

    public function holdAction(): Action
    {
        return Action::make('hold')
            ->label('Hold')
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading('Hold this payout?')
            ->modalDescription('The creator gets an email asking for more information. The money stays locked until you approve or decline it.')
            ->schema([$this->noteField(required: true)])
            ->modalSubmitActionLabel('Hold')
            ->action(function (array $data): void {
                $this->decide(fn (ReviewPayout $review, string $staff): Payout => $review->hold((string) $this->selected, $staff, $this->noteFrom($data)), 'On hold. The creator has been asked for more information.');
            });
    }

    public function declineAction(): Action
    {
        return Action::make('decline')
            ->label('Decline')
            ->color('danger')
            ->outlined()
            ->requiresConfirmation()
            ->modalHeading('Decline this payout?')
            ->modalDescription('The money goes back to the creator\'s available earnings and they get an email. They can ask again.')
            ->schema([$this->noteField(required: true)])
            ->modalSubmitActionLabel('Decline')
            ->action(function (array $data): void {
                $this->decide(fn (ReviewPayout $review, string $staff): Payout => $review->decline((string) $this->selected, $staff, $this->noteFrom($data)), 'Declined. The money is back in the creator\'s earnings.');
            });
    }

    /** The note is asked for in the dialog and checked before anything changes. */
    private function noteField(bool $required): Textarea
    {
        return Textarea::make('note')
            ->label($required ? 'Note (saved to the audit log)' : 'Note (optional, saved to the audit log)')
            ->helperText('The creator never sees it.')
            ->rows(3)
            ->required($required)
            ->minLength($required ? 3 : 0)
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

    /**
     * Canvas tiles: waiting, auto-paid today (in the viewer's day, D-15), failed in the
     * last 7 days, and whether payout_clearing holds exactly the payouts in progress.
     *
     * @return array{waiting: array{count: int, amount: int}, autoPaid: array{count: int, amount: int}, failed: int, clearing: array{reconciled: bool, held: int, difference: int, inProgress: int}}
     */
    private function tiles(): array
    {
        $today = CarbonImmutable::now(FilamentTimezone::get())->startOfDay()->utc();
        $autoPaid = Payout::query()->where('status', PayoutState::Sent)->whereNull('reviewed_by')->where('sent_at', '>=', $today);

        $inProgress = Payout::query()->whereIn('status', [PayoutState::Review, PayoutState::Held, PayoutState::Approved, PayoutState::Sending]);
        $owed = (int) (clone $inProgress)->sum('amount_minor');
        $held = app(LedgerIntegrity::class)->totalOf(AccountType::PayoutClearing);

        return [
            'waiting' => app(MoneyStats::class)->payoutsWaiting(),
            'autoPaid' => ['count' => (clone $autoPaid)->count(), 'amount' => (int) $autoPaid->sum('amount_minor')],
            'failed' => Payout::query()->where('status', PayoutState::Failed)->where('failed_at', '>=', now()->subDays(7))->count(),
            'clearing' => ['reconciled' => $held === $owed, 'held' => $held, 'difference' => $held - $owed, 'inProgress' => $inProgress->count()],
        ];
    }

    /**
     * Canvas "Payout detail" checks.
     *
     * @return list<array{text: string, tone: 'ok'|'warning'|'danger'|'muted'}>
     */
    private function checks(Payout $payout): array
    {
        $left = app(Ledger::class)->balance(AccountRef::of(AccountType::CreatorEarnings, $payout->creator_id))->amountMinor;
        // Earlier payouts only: once this one is sent it must not count itself.
        $sent = Payout::query()->where('creator_id', $payout->creator_id)->whereKeyNot($payout->id)->where('status', PayoutState::Sent)->count();
        $flags = $payout->flags;

        return [
            $left < 0
                ? ['text' => 'Earnings are '.self::money($left).' after refunds since the request', 'tone' => 'danger']
                : ['text' => 'Reserved from available earnings; '.self::money($left).' left after this', 'tone' => 'ok'],
            ['text' => 'Paid only from sales past the 7-day hold', 'tone' => 'ok'],
            $this->chargebackCheck($payout->creator_id),
            in_array(Payout::NEW_METHOD, $flags, true)
                ? ['text' => 'Payout method added in the last 7 days', 'tone' => 'warning']
                : ['text' => 'Payout method in use for more than 7 days', 'tone' => 'ok'],
            in_array(Payout::NEW_DEVICE, $flags, true)
                ? ['text' => 'Requested from a new device', 'tone' => 'warning']
                : ['text' => 'Requested from a device the creator has used before', 'tone' => 'ok'],
            in_array(Payout::NAME_MISMATCH, $flags, true)
                ? ['text' => 'Payout name does not match the ID', 'tone' => 'danger']
                : ['text' => 'Payout name matches the ID', 'tone' => 'ok'],
            $sent === 0
                ? ['text' => 'First payout for this creator', 'tone' => 'muted']
                : ['text' => $sent.' earlier '.($sent === 1 ? 'payout' : 'payouts').' sent', 'tone' => 'ok'],
            ['text' => 'Confirmed by the creator with a second factor', 'tone' => 'ok'],
        ];
    }

    /**
     * Canvas "Chargebacks on her sales: 0.2% (90d)": the share of the creator's sales in the
     * last 90 days that a chargeback clawed back (D-20).
     *
     * @return array{text: string, tone: 'ok'|'warning'|'danger'|'muted'}
     */
    private function chargebackCheck(string $creatorId): array
    {
        $sales = DB::table('earning_holds')->where('creator_id', $creatorId)->where('created_at', '>=', now()->subDays(90));
        $total = (clone $sales)->count();

        if ($total === 0) {
            return ['text' => 'No sales in the last 90 days', 'tone' => 'muted'];
        }

        // Clawed back and not returned by a won dispute.
        $bps = intdiv($sales->whereNotNull('dispute_id')->whereNotNull('refunded_at')->count() * 10_000, $total);

        return ['text' => 'Chargebacks on their sales: '.Disputes::percent($bps).' (90 days)', 'tone' => Disputes::rateTone($bps)];
    }

    private function detail(): ?Payout
    {
        return $this->selected === null ? null : Payout::query()->find($this->selected);
    }

    /**
     * @param  callable(ReviewPayout, string): Payout  $change
     */
    private function decide(callable $change, string $done): void
    {
        $staff = auth()->id();
        abort_unless(is_string($staff), 403);

        $this->resetErrorBag();

        try {
            $change(app(ReviewPayout::class), $staff);
        } catch (WalletException $e) {
            $this->addError($e->field, $e->getMessage());
            Notification::make()->title($e->getMessage())->danger()->send();

            return;
        }

        Notification::make()->title($done)->success()->send();
        // The sidebar badge (payouts waiting) is drawn with the page: redraw it.
        $this->dispatch('refresh-sidebar');
    }
}
