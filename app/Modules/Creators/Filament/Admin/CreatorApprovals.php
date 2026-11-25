<?php

declare(strict_types=1);

namespace App\Modules\Creators\Filament\Admin;

use App\Modules\Creators\Actions\ApproveCreator;
use App\Modules\Creators\Actions\AssignApplication;
use App\Modules\Creators\Actions\RejectCreator;
use App\Modules\Creators\Actions\RequestCreatorFix;
use App\Modules\Creators\Actions\RevealApplicantDob;
use App\Modules\Creators\Contracts\ApprovalQueue;
use App\Modules\Creators\Enums\CreatorStatus;
use App\Modules\Creators\Enums\FixKind;
use App\Modules\Creators\Exceptions\CreatorException;
use App\Modules\Creators\Models\Creator;
use App\Modules\Creators\Models\CreatorKyc;
use App\Modules\Identity\Contracts\Accounts;
use App\Modules\Identity\Contracts\StaffAbility;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;

/**
 * Canvas board AdminApprovals (plan M8a, use cases 4–8); replaces the D-10 minimal page.
 * The queue is oldest first, with a detail pane: the partner's checks, age, document
 * type and last 4, whether the payout name matches the ID, and the decisions.
 *
 * Rule 13: staff see masked details only. The date of birth needs a logged reveal and
 * the ID image is fetched from the partner on demand (D-01). The legal name and payout
 * numbers are never shown. Every decision is checked again and audited in its Action.
 */
final class CreatorApprovals extends Page
{
    private const int PER_PAGE = 25;

    // Canvas AdminOverview sidebar: text items, no icons, a count of what is waiting.
    protected static string|BackedEnum|null $navigationIcon = null;

    protected static ?string $navigationLabel = 'Creator approvals';

    protected static ?int $navigationSort = 2;

    protected static ?string $title = 'Creator approvals';

    protected static ?string $slug = 'creator-approvals';

    protected string $view = 'creators::admin.approvals';

    #[Url(except: '')]
    public string $search = '';

    #[Url(as: 'application')]
    public ?string $selected = null;

    #[Url(except: 1)]
    public int $page = 1;

    /** Shown after a logged reveal, for the open application only. */
    public ?string $revealedDob = null;

    /** SEC-15: moderators and admins (StaffAbility::ReviewCreators). */
    public static function canAccess(): bool
    {
        return Gate::allows(StaffAbility::ReviewCreators->value);
    }

    public static function getNavigationBadge(): ?string
    {
        $waiting = app(ApprovalQueue::class)->waiting();

        return $waiting > 0 ? (string) $waiting : null;
    }

    public static function getNavigationBadgeTooltip(): string
    {
        return 'Waiting for review';
    }

    public function getSubheading(): string
    {
        return number_format(app(ApprovalQueue::class)->waiting()).' waiting · automatic checks run first; you make the final call.';
    }

    public function updatedSearch(): void
    {
        // The first match opens, so the pane never shows one the search hides.
        $this->page = 1;
        $this->selected = null;
    }

    public function select(string $creatorId): void
    {
        $this->selected = $creatorId;
        $this->revealedDob = null;
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
        $staff = $this->staffId();
        $term = trim($this->search);

        $queue = Creator::query()
            ->where('status', CreatorStatus::Submitted)
            ->where('user_id', '!=', $staff)
            ->when($term !== '', static fn (Builder $q): Builder => $q->where(static fn (Builder $q): Builder => $q
                ->where('display_name', 'ilike', '%'.addcslashes($term, '%_\\').'%')
                ->orWhere('handle', 'ilike', '%'.addcslashes(ltrim($term, '@'), '%_\\').'%')))
            ->orderBy('submitted_at')
            ->orderBy('id')
            ->paginate(self::PER_PAGE, page: $this->page);

        // Like the canvas, the first in the queue is open until another is chosen.
        $detail = $this->detail();
        if (! $detail instanceof Creator && $queue->isNotEmpty()) {
            $first = $queue->getCollection()->first();
            $this->selected = $first instanceof Creator ? $first->id : null;
            $this->revealedDob = null;
            $detail = $this->detail();
        }

        return [
            'queue' => $queue,
            'detail' => $detail,
            'holder' => $detail instanceof Creator ? $this->holderLabel($detail, $staff) : null,
        ];
    }

    public static function categoryLabel(?string $key): string
    {
        $label = config()->array('fanfolio.creators.categories')[$key] ?? null;

        return is_string($label) ? $label : (string) $key;
    }

    public static function documentType(?CreatorKyc $check): string
    {
        $types = ['national_id' => 'National ID', 'passport' => 'Passport', 'driving_licence' => 'Driving licence'];

        return $types[(string) $check?->id_type] ?? 'ID';
    }

    /**
     * @param  LengthAwarePaginator<int, Creator>  $queue
     */
    public static function pageLabel(LengthAwarePaginator $queue): string
    {
        return 'Page '.$queue->currentPage().' of '.max(1, $queue->lastPage());
    }

    public function approveAction(): Action
    {
        return Action::make('approve')
            ->label('Approve creator')
            ->color('primary')
            ->requiresConfirmation()
            ->modalHeading('Approve this creator?')
            ->modalDescription('They can start posting and earning straight away.')
            ->modalSubmitActionLabel('Approve')
            ->visible(fn (): bool => $this->detail() instanceof Creator)
            ->action(function (): void {
                $this->run(fn (string $staff): Creator => app(ApproveCreator::class)->handle((string) $this->selected, $staff), 'Approved.');
            });
    }

    public function requestChangesAction(): Action
    {
        return Action::make('requestChanges')
            ->label('Request changes')
            ->color('gray')
            ->modalHeading('Request changes')
            ->modalDescription('The creator sees "One thing to fix" and submits again.')
            ->modalSubmitActionLabel('Send to the creator')
            ->visible(fn (): bool => $this->detail() instanceof Creator)
            ->schema([
                Select::make('kind')->label('What to fix')->required()
                    ->options(collect(FixKind::cases())->mapWithKeys(static fn (FixKind $kind): array => [$kind->value => $kind->label()])->all()),
                Textarea::make('note')->label('Note to the creator (optional)')->maxLength(300)
                    ->helperText('Shown to the creator. No personal data.'),
            ])
            ->action(function (array $data): void {
                $this->run(fn (string $staff): Creator => app(RequestCreatorFix::class)->handle(
                    (string) $this->selected,
                    $staff,
                    FixKind::from(is_string($data['kind'] ?? null) ? $data['kind'] : ''),
                    is_string($data['note'] ?? null) ? $data['note'] : null,
                ), 'Sent back to the creator.');
            });
    }

    public function rejectAction(): Action
    {
        return Action::make('reject')
            ->label('Reject')
            ->color('danger')
            ->outlined()
            ->modalHeading('Reject this application?')
            ->modalDescription('The creator cannot apply again with this account. They see a neutral message; the reason stays in the audit log.')
            ->modalSubmitActionLabel('Reject')
            ->visible(fn (): bool => $this->detail() instanceof Creator)
            ->schema([
                Select::make('reason')->label('Reason')->required()->options(RejectCreator::REASONS),
            ])
            ->action(function (array $data): void {
                $this->run(fn (string $staff): Creator => app(RejectCreator::class)->handle(
                    (string) $this->selected,
                    $staff,
                    is_string($data['reason'] ?? null) ? $data['reason'] : '',
                ), 'Rejected.');
            });
    }

    public function assignAction(): Action
    {
        return Action::make('assign')
            ->label('Assign to me')
            ->color('gray')
            ->size('sm')
            ->visible(fn (): bool => ($detail = $this->detail()) instanceof Creator && $detail->assigned_to !== auth()->id())
            ->action(function (): void {
                $this->run(fn (string $staff): Creator => app(AssignApplication::class)->assign((string) $this->selected, $staff), 'Assigned to you.');
            });
    }

    public function releaseAction(): Action
    {
        return Action::make('release')
            ->label('Unassign')
            ->color('gray')
            ->size('sm')
            ->visible(fn (): bool => ($detail = $this->detail()) instanceof Creator && $detail->assigned_to === auth()->id())
            ->action(function (): void {
                $this->run(fn (string $staff): Creator => app(AssignApplication::class)->release((string) $this->selected, $staff), 'Unassigned.');
            });
    }

    public function revealDobAction(): Action
    {
        return Action::make('revealDob')
            ->label('Show date of birth')
            ->link()
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading('Show the date of birth?')
            ->modalDescription('The reveal is written to the audit log with your name.')
            ->modalSubmitActionLabel('Show')
            ->visible(fn (): bool => $this->revealedDob === null && $this->detail() instanceof Creator)
            ->action(function (): void {
                try {
                    $this->revealedDob = app(RevealApplicantDob::class)->handle((string) $this->selected, $this->staffId())->format('j M Y');
                } catch (CreatorException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();
                }
            });
    }

    /** The open application: the chosen one while it still waits, never the viewer's own. */
    private function detail(): ?Creator
    {
        if ($this->selected === null) {
            return null;
        }

        $creator = Creator::query()->whereKey($this->selected)->where('status', CreatorStatus::Submitted)->first();

        return $creator instanceof Creator && $creator->user_id !== auth()->id() ? $creator : null;
    }

    private function holderLabel(Creator $creator, string $staff): string
    {
        if ($creator->assigned_to === null) {
            return 'Not assigned';
        }

        if ($creator->assigned_to === $staff) {
            return 'Assigned to you';
        }

        $username = app(Accounts::class)->username($creator->assigned_to);

        return 'Held by '.($username !== null ? '@'.$username : 'another reviewer');
    }

    private function staffId(): string
    {
        $staff = auth()->id();

        if (! is_string($staff)) {
            abort(403);
        }

        return $staff;
    }

    /**
     * @param  callable(string): Creator  $decision
     */
    private function run(callable $decision, string $done): void
    {
        try {
            $decision($this->staffId());
        } catch (CreatorException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return;
        }

        Notification::make()->title($done)->success()->send();
        // The sidebar counts (waiting applications) are drawn with the page: redraw them.
        $this->dispatch('refresh-sidebar');
    }
}
