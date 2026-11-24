<?php

declare(strict_types=1);

namespace App\Modules\TrustSafety\Filament\Admin;

use App\Modules\Identity\Contracts\AccountDirectory;
use App\Modules\Identity\Contracts\Data\AccountSummary;
use App\Modules\Identity\Contracts\StaffAbility;
use App\Modules\TrustSafety\Actions\BanAccount;
use App\Modules\TrustSafety\Actions\FlagAccount;
use App\Modules\TrustSafety\Actions\LiftRestriction;
use App\Modules\TrustSafety\Actions\SignOutAccount;
use App\Modules\TrustSafety\Actions\Strikes;
use App\Modules\TrustSafety\Actions\SuspendAccount;
use App\Modules\TrustSafety\Contracts\RestrictionReason;
use App\Modules\TrustSafety\Enums\ReportReason;
use App\Modules\TrustSafety\Exceptions\TrustSafetyException;
use App\Modules\TrustSafety\Models\Strike;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Url;

/**
 * Canvas board AdminUsers (plan M8a, use cases 14–20 and 24): search and filter
 * accounts, a detail pane, and the decisions. Each decision is checked again and
 * audited inside its Action; this page only shows what the viewer's role allows.
 * Spend, disputes and leak matches on the board arrive with M3 and M4.
 */
final class UsersBoard extends Page
{
    public const array FILTERS = ['all' => 'All', 'creator' => 'Creators', 'fan' => 'Fans', 'flagged' => 'Flagged', 'suspended' => 'Suspended', 'banned' => 'Banned'];

    private const int PER_PAGE = 25;

    protected static string|BackedEnum|null $navigationIcon = null;

    protected static ?string $navigationLabel = 'Users & bans';

    protected static ?int $navigationSort = 7;

    protected static ?string $title = 'Users & bans';

    protected static ?string $slug = 'accounts';

    protected string $view = 'trustsafety::admin.users';

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: 'all')]
    public string $filter = 'all';

    #[Url(as: 'user')]
    public ?string $selected = null;

    #[Url(except: 1)]
    public int $page = 1;

    public string $reason = '';

    public string $note = '';

    public int $days = 7;

    public static function canAccess(): bool
    {
        return Gate::allows(StaffAbility::ViewUsers->value);
    }

    public function getSubheading(): string
    {
        $counts = app(AccountDirectory::class)->counts(CarbonImmutable::now(FilamentTimezone::get())->startOfMonth());

        return sprintf(
            '%s %s · %s %s · %s banned this month',
            number_format($counts['fan']),
            Str::plural('fan', $counts['fan']),
            number_format($counts['creator']),
            Str::plural('creator', $counts['creator']),
            number_format($counts['banned_since']),
        );
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

    public function select(string $userId): void
    {
        $this->selected = $userId;
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
        $directory = app(AccountDirectory::class);

        $accounts = $directory->search(
            $this->search,
            $filter === 'flagged' ? 'all' : $filter,
            $filter === 'flagged' ? Strikes::flaggedUserIds() : null,
            self::PER_PAGE,
            $this->page,
        );

        $ids = array_values(array_map(static fn (AccountSummary $a): string => $a->id, $accounts->items()));
        $selected = $this->selected !== null ? $directory->find($this->selected) : null;

        return [
            'filters' => self::FILTERS,
            'active' => $filter,
            'accounts' => $accounts,
            'strikes' => Strikes::activeCounts($ids),
            'detail' => $selected,
            'detailStrikes' => $selected instanceof AccountSummary
                ? Strike::query()->active()->where('user_id', $selected->id)->latest('created_at')->get()
                : collect(),
            'reasons' => RestrictionReason::options(),
            'dayOptions' => Gate::allows(StaffAbility::SuspendUserLong->value) ? SuspendAccount::DAYS : [1, 7],
        ];
    }

    /**
     * @return array{text: string, tone: 'ok'|'warning'|'danger'|'muted'}
     */
    public static function statusPill(AccountSummary $account, int $strikes): array
    {
        $zone = FilamentTimezone::get();

        return match (true) {
            $account->status === AccountSummary::BANNED => ['text' => 'Banned'.($account->restrictedAt instanceof CarbonImmutable ? ' '.$account->restrictedAt->setTimezone($zone)->format('j M') : ''), 'tone' => 'danger'],
            $account->status === AccountSummary::SUSPENDED => ['text' => 'Suspended to '.($account->suspendedUntil?->setTimezone($zone)->format('j M') ?? ''), 'tone' => 'warning'],
            $strikes >= Strike::FLAG_AT => ['text' => 'Flagged · '.$strikes.' strikes', 'tone' => 'warning'],
            $account->status === AccountSummary::PAUSED => ['text' => 'Paused', 'tone' => 'muted'],
            default => ['text' => 'Active', 'tone' => 'ok'],
        };
    }

    public function banAction(): Action
    {
        return Action::make('ban')
            ->label('Ban user')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Ban this account?')
            ->modalDescription('They are signed out everywhere, cannot sign in, and a creator page stops earning. Only an admin can lift a ban.')
            ->modalSubmitActionLabel('Ban')
            ->visible(fn (): bool => $this->detailAllows(StaffAbility::BanUser, static fn (AccountSummary $a): bool => $a->status !== AccountSummary::BANNED))
            ->action(function (): void {
                $this->decide(function (string $staff): void {
                    app(BanAccount::class)->handle((string) $this->selected, $staff, $this->reasonChosen(), $this->note);
                }, 'Banned.');
            });
    }

    public function suspendAction(): Action
    {
        return Action::make('suspend')
            ->label(fn (): string => 'Suspend '.$this->days.' '.($this->days === 1 ? 'day' : 'days'))
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading('Suspend this account?')
            ->modalDescription('They are signed out everywhere and cannot sign in until the suspension ends. It lifts by itself.')
            ->modalSubmitActionLabel('Suspend')
            ->visible(fn (): bool => $this->detailAllows(StaffAbility::SuspendUser, static fn (AccountSummary $a): bool => $a->status !== AccountSummary::BANNED))
            ->action(function (): void {
                $this->decide(fn (string $staff): mixed => app(SuspendAccount::class)->handle((string) $this->selected, $staff, $this->days, $this->reasonChosen(), $this->note), 'Suspended.');
            });
    }

    public function liftAction(): Action
    {
        return Action::make('lift')
            ->label('Lift restriction')
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading('Lift the restriction?')
            ->modalDescription('The account can sign in again. A creator page suspended by a ban stays suspended.')
            ->modalSubmitActionLabel('Lift')
            ->visible(fn (): bool => $this->detailAllows(StaffAbility::LiftRestriction, static fn (AccountSummary $a): bool => $a->isRestricted()))
            ->action(function (): void {
                $this->decide(function (string $staff): void {
                    app(LiftRestriction::class)->handle((string) $this->selected, $staff, $this->note);
                }, 'Restriction lifted.');
            });
    }

    public function strikeAction(): Action
    {
        return Action::make('strike')
            ->label('Give a strike')
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading('Give a strike?')
            ->modalDescription('The user gets an email with the reason category. Three strikes flag the account for a ban decision.')
            ->modalSubmitActionLabel('Give strike')
            ->visible(fn (): bool => $this->detailAllows(StaffAbility::GiveStrike, static fn (AccountSummary $a): bool => $a->status !== AccountSummary::BANNED))
            ->action(function (): void {
                $this->decide(fn (string $staff): mixed => app(Strikes::class)->give((string) $this->selected, $staff, $this->reasonChosen(), $this->note), 'Strike given.');
            });
    }

    public function signOutAction(): Action
    {
        return Action::make('signOut')
            ->label('Sign out everywhere')
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading('Sign out on every device?')
            ->modalDescription('The account stays open and can sign in again.')
            ->modalSubmitActionLabel('Sign out')
            ->visible(fn (): bool => $this->detailAllows(StaffAbility::SignOutUser, static fn (AccountSummary $a): bool => $a->activeDevices > 0))
            ->action(function (): void {
                $this->decide(fn (string $staff): mixed => app(SignOutAccount::class)->handle((string) $this->selected, $staff), 'Signed out everywhere.');
            });
    }

    public function flagAction(): Action
    {
        return Action::make('flag')
            ->label('Flag for review')
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading('Flag this account for review?')
            ->modalDescription('It opens a case in the moderation queue with your note. Nothing happens to the account yet.')
            ->modalSubmitActionLabel('Flag')
            ->visible(fn (): bool => $this->detailAllows(StaffAbility::FlagUser, static fn (AccountSummary $a): bool => true))
            ->action(function (): void {
                $this->decide(function (string $staff): void {
                    app(FlagAccount::class)->handle((string) $this->selected, $staff, ReportReason::fromRestriction($this->reasonChosen()), $this->note);
                }, 'Flagged. The case is in the moderation queue.');
            });
    }

    public function removeStrikeAction(): Action
    {
        return Action::make('removeStrike')
            ->label('Remove')
            ->link()
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading('Remove this strike?')
            ->modalDescription('Write why in the reason box first; it is saved to the audit log.')
            ->modalSubmitActionLabel('Remove strike')
            ->visible(fn (): bool => Gate::allows(StaffAbility::GiveStrike->value))
            ->action(function (array $arguments): void {
                $strike = $arguments['strike'] ?? null;
                $this->decide(function (string $staff) use ($strike): void {
                    app(Strikes::class)->remove(is_string($strike) ? $strike : '', $staff, $this->note);
                }, 'Strike removed.');
            });
    }

    /**
     * @param  callable(AccountSummary): bool  $when
     */
    private function detailAllows(StaffAbility $ability, callable $when): bool
    {
        if ($this->selected === null || ! Gate::allows($ability->value)) {
            return false;
        }

        $account = app(AccountDirectory::class)->find($this->selected);

        return $account instanceof AccountSummary && ! $account->isStaff && $account->id !== auth()->id() && $when($account);
    }

    private function reasonChosen(): RestrictionReason
    {
        return RestrictionReason::tryFrom($this->reason) ?? throw new TrustSafetyException('Choose a reason.', 'reason');
    }

    /**
     * @param  callable(string): mixed  $decision
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
        } catch (TrustSafetyException $e) {
            $this->addError($e->field, $e->getMessage());
            Notification::make()->title($e->getMessage())->danger()->send();

            return;
        }

        $this->note = '';
        Notification::make()->title($done)->success()->send();
    }

    /**
     * @param  LengthAwarePaginator<int, AccountSummary>  $accounts
     */
    public static function pageLabel(LengthAwarePaginator $accounts): string
    {
        return 'Page '.$accounts->currentPage().' of '.max(1, $accounts->lastPage());
    }
}
