<?php

declare(strict_types=1);

namespace App\Modules\Identity\Filament\Admin;

use App\Modules\Identity\Actions\PlatformSettingsStore;
use App\Modules\Identity\Actions\SettingProposals;
use App\Modules\Identity\Actions\SettingValues;
use App\Modules\Identity\Contracts\Accounts;
use App\Modules\Identity\Contracts\Setting;
use App\Modules\Identity\Contracts\StaffAbility;
use App\Modules\Identity\Exceptions\IdentityException;
use App\Modules\Identity\Models\SettingProposal;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Canvas board AdminSettings (plan M8a use cases 34, 35 and 42; M3 PR 7; D-16, D-24).
 * The values the brief locks are shown read-only. The others are edited here and sent
 * for approval together, with one reason; each becomes a proposal another admin
 * approves or rejects (SettingProposals). Nothing changes until then.
 */
final class SettingsBoard extends Page
{
    /** Switches on the board, by Livewire key. */
    public const array SWITCHES = [
        'crypto' => Setting::CryptoTopUps,
        'vpnStaff' => Setting::VpnStaffSignIn,
        'vpnKyc' => Setting::VpnCreatorKyc,
        'vpnPayouts' => Setting::VpnPayouts,
        'vpnTopUps' => Setting::VpnTopUps,
    ];

    protected static string|BackedEnum|null $navigationIcon = null;

    protected static ?string $navigationLabel = 'Fees & settings';

    protected static ?int $navigationSort = 9;

    protected static ?string $title = 'Fees & settings';

    protected static ?string $slug = 'settings';

    protected string $view = 'identity::admin.settings';

    /** Dollars, as typed ("20" or "12.50"). */
    public string $payoutMinimum = '';

    /** Whole dollars per pack, smallest first ("5 · 10 · 25 · 50"). */
    public string $packAmounts = '';

    /** Whole-percent bonus per pack ("0 · 0 · 4 · 10"). */
    public string $packBonuses = '';

    /** @var array<string, bool> */
    public array $switches = [];

    public static function canAccess(): bool
    {
        return Gate::allows(StaffAbility::ViewSettings->value);
    }

    public static function getNavigationBadge(): ?string
    {
        $pending = SettingProposal::query()->where('status', SettingProposal::PENDING)->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function getNavigationBadgeTooltip(): string
    {
        return 'Waiting for a second admin';
    }

    public function getSubheading(): string
    {
        return 'Money and safety settings need a second admin to approve before they take effect.';
    }

    /**
     * Canvas: "Request approval" sits beside the heading.
     *
     * @return list<Action>
     */
    protected function getHeaderActions(): array
    {
        return Gate::allows(StaffAbility::ProposeSettings->value) ? [$this->requestApprovalAction()] : [];
    }

    public function mount(): void
    {
        $this->loadCurrent();
    }

    /**
     * "5 · 10 · 25 · 50" from cents; "0 · 0 · 4 · 10" from basis points.
     *
     * @param  list<int>  $numbers
     */
    public static function joined(array $numbers, int $divisor): string
    {
        return implode(' · ', array_map(static fn (int $n): string => (string) intdiv($n, $divisor), $numbers));
    }

    /**
     * Whole numbers typed with commas, dots or spaces between them; null when anything
     * else is there.
     *
     * @return list<int>|null
     */
    public static function numbers(string $typed): ?array
    {
        $parts = preg_split('/[\s,·;\/]+/u', trim($typed), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        foreach ($parts as $part) {
            if (preg_match('/^\d{1,6}$/', $part) !== 1) {
                return null;
            }
        }

        return array_map(intval(...), $parts);
    }

    /** Typed dollars to cents, no floats; null when it is not an amount. */
    public static function cents(string $typed): ?int
    {
        $typed = str_replace([',', '$', ' '], '', trim($typed));

        if (preg_match('/^(\d{1,7})(?:\.(\d{1,2}))?$/', $typed, $m) !== 1) {
            return null;
        }

        return (int) $m[1] * 100 + (int) str_pad($m[2] ?? '0', 2, '0');
    }

    public function requestApprovalAction(): Action
    {
        return Action::make('requestApproval')
            ->label('Request approval')
            ->color('primary')
            ->requiresConfirmation()
            ->modalHeading('Send these changes for approval?')
            ->modalDescription('Another admin has to approve each change before it takes effect.')
            ->schema([
                Textarea::make('reason')
                    ->label('Why (saved to the audit log)')
                    ->rows(3)
                    ->required()
                    ->minLength(3)
                    ->maxLength(500)
                    ->validationMessages(['required' => 'Say why this should change.', 'min' => 'Say why this should change.']),
            ])
            ->modalSubmitActionLabel('Request approval')
            ->action(function (array $data): void {
                $this->propose(is_string($data['reason'] ?? null) ? $data['reason'] : '');
            });
    }

    public function approveAction(): Action
    {
        return Action::make('approve')
            ->label('Approve')
            ->color('primary')
            ->size('sm')
            ->requiresConfirmation()
            ->modalHeading('Approve this change?')
            ->modalDescription('It takes effect at once.')
            ->schema([Textarea::make('note')->label('Note (optional, saved to the audit log)')->rows(2)->maxLength(500)])
            ->modalSubmitActionLabel('Approve')
            ->action(function (array $data, array $arguments): void {
                $this->decide(fn (SettingProposals $p, string $staff, string $id): SettingProposal => $p->approve($id, $staff, is_string($data['note'] ?? null) ? $data['note'] : ''), $arguments, 'Approved. The new setting is live.');
            });
    }

    public function rejectAction(): Action
    {
        return Action::make('reject')
            ->label('Reject')
            ->color('gray')
            ->size('sm')
            ->requiresConfirmation()
            ->modalHeading('Reject this change?')
            ->schema([
                Textarea::make('note')
                    ->label('Note (saved to the audit log)')
                    ->rows(2)
                    ->required()
                    ->minLength(3)
                    ->maxLength(500)
                    ->validationMessages(['required' => 'Write a note for the audit log.', 'min' => 'Write a note for the audit log.']),
            ])
            ->modalSubmitActionLabel('Reject')
            ->action(function (array $data, array $arguments): void {
                $this->decide(fn (SettingProposals $p, string $staff, string $id): SettingProposal => $p->reject($id, $staff, is_string($data['note'] ?? null) ? $data['note'] : ''), $arguments, 'Rejected. Nothing changed.');
            });
    }

    public function withdrawAction(): Action
    {
        return Action::make('withdraw')
            ->label('Withdraw')
            ->color('gray')
            ->size('sm')
            ->requiresConfirmation()
            ->modalHeading('Withdraw your proposal?')
            ->modalDescription('Nothing changes. You can propose it again later.')
            ->modalSubmitActionLabel('Withdraw')
            ->action(function (array $arguments): void {
                $this->decide(fn (SettingProposals $p, string $staff, string $id): SettingProposal => $p->withdraw($id, $staff), $arguments, 'Withdrawn.');
            });
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $pending = SettingProposal::query()->where('status', SettingProposal::PENDING)->orderBy('proposed_at')->get();
        $history = SettingProposal::query()->where('status', '!=', SettingProposal::PENDING)->orderByDesc('decided_at')->limit(10)->get();

        $accounts = app(Accounts::class);
        $names = [];
        foreach ([...$pending, ...$history] as $proposal) {
            foreach ([$proposal->proposed_by, $proposal->decided_by] as $id) {
                if ($id !== null) {
                    $names[$id] ??= $accounts->username($id);
                }
            }
        }

        return [
            'pending' => $pending,
            'history' => $history,
            'names' => $names,
            'me' => auth()->id(),
            'canPropose' => Gate::allows(StaffAbility::ProposeSettings->value),
            'locked' => $this->locked(),
        ];
    }

    /**
     * The values the brief locks (rules 3 and 6, M8a use case 34), read from config.
     *
     * @return array{fee: string, hold: string, review: string, credits: string, devices: string}
     */
    private function locked(): array
    {
        $creditsPerDollar = config()->integer('fanfolio.money.credits_per_usd');

        return [
            'fee' => intdiv(config()->integer('fanfolio.money.platform_fee_bps'), 100).'%',
            'hold' => config()->integer('fanfolio.money.earnings_hold_days').' days',
            'review' => SettingValues::dollars(config()->integer('fanfolio.money.payout_manual_review_above_minor')),
            'credits' => number_format($creditsPerDollar).' credits = $1.00',
            'devices' => (string) config()->integer('fanfolio.identity.max_active_devices'),
        ];
    }

    /** The form shows the settings as they are now. */
    private function loadCurrent(): void
    {
        $store = app(PlatformSettingsStore::class);
        $packs = $store->topUpPacks();

        $this->payoutMinimum = mb_substr(SettingValues::dollars($store->integer(Setting::PayoutMinimum)), 1);
        $this->packAmounts = self::joined(array_column($packs, 'amount_minor'), 100);
        $this->packBonuses = self::joined(array_column($packs, 'bonus_bps'), 100);
        foreach (self::SWITCHES as $key => $setting) {
            $this->switches[$key] = $store->enabled($setting);
        }
    }

    /**
     * What the form holds, as setting values (invalid input becomes null, which the
     * proposal check refuses with the setting's own message).
     *
     * @return array<string, mixed> setting value => typed value
     */
    private function typed(): array
    {
        $amounts = self::numbers($this->packAmounts);
        $bonuses = self::numbers($this->packBonuses);
        $packs = null;
        if ($amounts !== null && $bonuses !== null && count($amounts) === count($bonuses)) {
            $packs = array_map(static fn (int $dollars, int $percent): array => ['amount_minor' => $dollars * 100, 'bonus_bps' => $percent * 100], $amounts, $bonuses);
        }

        $typed = [
            Setting::PayoutMinimum->value => self::cents($this->payoutMinimum),
            Setting::TopUpPacks->value => $packs,
        ];
        foreach (self::SWITCHES as $key => $setting) {
            $typed[$setting->value] = (bool) ($this->switches[$key] ?? false);
        }

        return $typed;
    }

    private function propose(string $reason): void
    {
        $staff = auth()->id();
        abort_unless(is_string($staff), 403);
        $this->resetErrorBag();

        $store = app(PlatformSettingsStore::class);
        $changes = [];
        foreach ($this->typed() as $key => $value) {
            $setting = Setting::from($key);
            if (! SettingValues::same($value, $store->value($setting))) {
                $changes[] = [$setting, $value];
            }
        }

        if ($changes === []) {
            Notification::make()->title('Nothing changed. Edit a setting first.')->warning()->send();

            return;
        }

        try {
            // All or none: one bad value sends nothing.
            DB::transaction(function () use ($changes, $staff, $reason): void {
                foreach ($changes as [$setting, $value]) {
                    app(SettingProposals::class)->propose($setting, $value, $staff, $reason);
                }
            });
        } catch (IdentityException $e) {
            $this->addError($e->field, $e->getMessage());
            Notification::make()->title($e->getMessage())->danger()->send();

            return;
        }

        $this->loadCurrent();
        Notification::make()->title(count($changes) === 1 ? 'Sent for approval.' : count($changes).' changes sent for approval.')->success()->send();
        $this->dispatch('refresh-sidebar');
    }

    /**
     * @param  callable(SettingProposals, string, string): SettingProposal  $change
     * @param  array<array-key, mixed>  $arguments
     */
    private function decide(callable $change, array $arguments, string $done): void
    {
        $staff = auth()->id();
        abort_unless(is_string($staff), 403);
        $this->resetErrorBag();

        try {
            $change(app(SettingProposals::class), $staff, is_string($arguments['proposal'] ?? null) ? $arguments['proposal'] : '');
        } catch (IdentityException $e) {
            $this->addError($e->field, $e->getMessage());
            Notification::make()->title($e->getMessage())->danger()->send();

            return;
        }

        $this->loadCurrent();
        Notification::make()->title($done)->success()->send();
        $this->dispatch('refresh-sidebar');
    }
}
