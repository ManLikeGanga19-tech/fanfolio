<?php

declare(strict_types=1);

namespace App\Modules\Creators\Filament\Admin;

use App\Modules\Creators\Actions\ReinstateCreator;
use App\Modules\Creators\Actions\RequireIdRecheck;
use App\Modules\Creators\Actions\SuspendCreator;
use App\Modules\Creators\Contracts\CreatorEligibility;
use App\Modules\Creators\Enums\CreatorStatus;
use App\Modules\Creators\Enums\KycStatus;
use App\Modules\Creators\Exceptions\CreatorException;
use App\Modules\Creators\Models\Creator;
use App\Modules\Identity\Contracts\AccountRestrictions;
use App\Modules\Identity\Contracts\Accounts;
use App\Modules\Identity\Contracts\StaffAbility;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;

/**
 * Plan M8a use cases 9–12: every creator page by status, a detail pane (page, ID checks
 * as results only, payout methods masked, key dates) and the staff decisions: suspend
 * (admin), pause up to 7 days (moderator and admin), reinstate (admin) and require a
 * new ID check. There is no canvas board for it; it follows Users & bans (AdminUsers).
 * Each decision is checked again and audited inside its Action. "Pause payouts only"
 * (use case 13) comes with payouts in M3. The full status history arrives with the
 * Audit log board (PR 5).
 */
final class CreatorsDirectory extends Page
{
    public const array FILTERS = [
        'all' => 'All',
        'active' => 'Active',
        'submitted' => 'In review',
        'onboarding' => 'Onboarding',
        'suspended' => 'Suspended',
        'rejected' => 'Rejected',
    ];

    private const int PER_PAGE = 25;

    protected static string|BackedEnum|null $navigationIcon = null;

    protected static ?string $navigationLabel = 'Creators';

    protected static ?int $navigationSort = 6;

    protected static ?string $title = 'Creators';

    protected static ?string $slug = 'creators';

    protected string $view = 'creators::admin.directory';

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: 'all')]
    public string $filter = 'all';

    #[Url(as: 'creator')]
    public ?string $selected = null;

    #[Url(except: 1)]
    public int $page = 1;

    public string $reason = '';

    public string $note = '';

    public int $days = 7;

    public static function canAccess(): bool
    {
        return Gate::allows(StaffAbility::ViewCreators->value);
    }

    public function getSubheading(): string
    {
        $active = Creator::query()->where('status', CreatorStatus::Active)->count();
        $suspended = Creator::query()->where('status', CreatorStatus::Suspended)->count();

        return number_format($active).' active · '.number_format($suspended).' suspended or paused';
    }

    public function updatedSearch(): void
    {
        $this->page = 1;
    }

    public function setFilter(string $filter): void
    {
        $this->filter = array_key_exists($filter, self::FILTERS) ? $filter : 'all';
        $this->page = 1;
    }

    public function select(string $creatorId): void
    {
        $this->selected = $creatorId;
        $this->reason = '';
        $this->note = '';
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
        $filter = array_key_exists($this->filter, self::FILTERS) ? $this->filter : 'all';
        $term = trim($this->search);

        $creators = Creator::query()
            ->whereNotNull('handle')
            ->when($filter === 'active', static fn (Builder $q): Builder => $q->where('status', CreatorStatus::Active))
            ->when($filter === 'submitted', static fn (Builder $q): Builder => $q->where('status', CreatorStatus::Submitted))
            ->when($filter === 'suspended', static fn (Builder $q): Builder => $q->where('status', CreatorStatus::Suspended))
            ->when($filter === 'onboarding', static fn (Builder $q): Builder => $q->where('status', CreatorStatus::Onboarding)->where('kyc_status', '!=', KycStatus::Rejected))
            ->when($filter === 'rejected', static fn (Builder $q): Builder => $q->where('status', CreatorStatus::Onboarding)->where('kyc_status', KycStatus::Rejected))
            ->when($term !== '', static fn (Builder $q): Builder => $q->where(static fn (Builder $q): Builder => $q
                ->where('display_name', 'ilike', '%'.addcslashes($term, '%_\\').'%')
                ->orWhere('handle', 'ilike', '%'.addcslashes(ltrim($term, '@'), '%_\\').'%')))
            ->orderByDesc('updated_at')
            ->orderBy('id')
            ->paginate(self::PER_PAGE, page: $this->page);

        $detail = $this->detail();

        return [
            'filters' => self::FILTERS,
            'active' => $filter,
            'creators' => $creators,
            'detail' => $detail,
            'username' => $detail instanceof Creator ? app(Accounts::class)->username($detail->user_id) : null,
            'accountRestricted' => $detail instanceof Creator && app(AccountRestrictions::class)->isRestricted($detail->user_id),
            'checks' => $detail instanceof Creator ? $detail->kycChecks()->latest('created_at')->limit(5)->get() : collect(),
            'payoutMethods' => $detail instanceof Creator ? $detail->payoutMethods()->latest('created_at')->get() : collect(),
            'reasons' => SuspendCreator::REASONS,
            'can' => [
                'suspend' => Gate::allows(StaffAbility::SuspendCreator->value),
                'pause' => Gate::allows(StaffAbility::PauseCreator->value),
                'reinstate' => Gate::allows(StaffAbility::ReinstateCreator->value),
                'recheck' => Gate::allows(StaffAbility::RequireIdRecheck->value),
            ],
            'isOwn' => $detail instanceof Creator && $detail->user_id === auth()->id(),
        ];
    }

    /**
     * @return array{text: string, tone: 'ok'|'warning'|'danger'|'muted'}
     */
    public static function statusPill(Creator $creator): array
    {
        $zone = FilamentTimezone::get();

        return match (true) {
            $creator->status === CreatorStatus::Suspended && $creator->suspended_until !== null => ['text' => 'Paused to '.$creator->suspended_until->setTimezone($zone)->format('j M, H:i'), 'tone' => 'warning'],
            $creator->status === CreatorStatus::Suspended => ['text' => 'Suspended', 'tone' => 'danger'],
            $creator->status === CreatorStatus::Active => app(CreatorEligibility::class)->canPublish($creator->user_id)
                ? ['text' => 'Active', 'tone' => 'ok']
                : ['text' => 'Active · cannot publish', 'tone' => 'warning'],
            $creator->status === CreatorStatus::Submitted => ['text' => 'In review', 'tone' => 'muted'],
            $creator->kyc_status === KycStatus::Rejected => ['text' => 'Rejected', 'tone' => 'danger'],
            $creator->fix_kind !== null => ['text' => 'Fix requested', 'tone' => 'warning'],
            default => ['text' => 'Onboarding', 'tone' => 'muted'],
        };
    }

    /**
     * @param  LengthAwarePaginator<int, Creator>  $creators
     */
    public static function pageLabel(LengthAwarePaginator $creators): string
    {
        return 'Page '.$creators->currentPage().' of '.max(1, $creators->lastPage());
    }

    public function suspendAction(): Action
    {
        return Action::make('suspend')
            ->label('Suspend')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Suspend this creator page?')
            ->modalDescription('Publishing and earning stop at once, until an admin reinstates the page. The creator is told the reason category, not your note.')
            ->modalSubmitActionLabel('Suspend')
            ->action(function (): void {
                $this->decide(fn (string $staff): Creator => app(SuspendCreator::class)->handle((string) $this->selected, $staff, $this->reasonChosen(), $this->noteGiven()), 'Suspended.');
            });
    }

    public function pauseAction(): Action
    {
        return Action::make('pause')
            ->label(fn (): string => 'Pause '.$this->days.' '.($this->days === 1 ? 'day' : 'days'))
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading('Pause this creator page?')
            ->modalDescription('Publishing and earning stop at once. The page reopens by itself when the pause ends.')
            ->modalSubmitActionLabel('Pause')
            ->action(function (): void {
                $this->decide(fn (string $staff): Creator => app(SuspendCreator::class)->handle((string) $this->selected, $staff, $this->reasonChosen(), $this->noteGiven(), $this->days), 'Paused.');
            });
    }

    public function reinstateAction(): Action
    {
        return Action::make('reinstate')
            ->label('Reinstate')
            ->color('primary')
            ->requiresConfirmation()
            ->modalHeading('Reinstate this creator page?')
            ->modalDescription('The creator can post and earn again. Write why in the note first; it is saved to the audit log.')
            ->modalSubmitActionLabel('Reinstate')
            ->action(function (): void {
                $this->decide(fn (string $staff): Creator => app(ReinstateCreator::class)->handle((string) $this->selected, $staff, $this->note), 'Reinstated.');
            });
    }

    public function recheckAction(): Action
    {
        return Action::make('recheck')
            ->label('Require a new ID check')
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading('Ask for a new ID check?')
            ->modalDescription('Publishing and earning stop at once. The creator verifies again and comes back through Creator approvals. Write why in the note first.')
            ->modalSubmitActionLabel('Ask for a new check')
            ->action(function (): void {
                $this->decide(fn (string $staff): Creator => app(RequireIdRecheck::class)->handle((string) $this->selected, $staff, $this->note), 'New ID check requested.');
            });
    }

    private function detail(): ?Creator
    {
        return $this->selected === null ? null : Creator::query()->find($this->selected);
    }

    private function reasonChosen(): string
    {
        return array_key_exists($this->reason, SuspendCreator::REASONS)
            ? $this->reason
            : throw new CreatorException('Choose a reason.', 'reason');
    }

    private function noteGiven(): string
    {
        return trim($this->note) !== '' ? $this->note : throw new CreatorException('Write why, for the audit log.', 'note');
    }

    /**
     * @param  callable(string): Creator  $decision
     */
    private function decide(callable $decision, string $done): void
    {
        $staff = auth()->id();

        if (! is_string($staff)) {
            abort(403);
        }

        $this->resetErrorBag();

        try {
            $decision($staff);
        } catch (CreatorException $e) {
            $this->addError($e->field, $e->getMessage());
            Notification::make()->title($e->getMessage())->danger()->send();

            return;
        }

        $this->note = '';
        $this->reason = '';
        Notification::make()->title($done)->success()->send();
    }
}
