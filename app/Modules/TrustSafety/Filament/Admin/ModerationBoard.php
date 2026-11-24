<?php

declare(strict_types=1);

namespace App\Modules\TrustSafety\Filament\Admin;

use App\Modules\Creators\Contracts\CreatorPages;
use App\Modules\Identity\Contracts\AccountDirectory;
use App\Modules\Identity\Contracts\Accounts;
use App\Modules\Identity\Contracts\StaffAbility;
use App\Modules\TrustSafety\Actions\AssignCase;
use App\Modules\TrustSafety\Actions\DecideCase;
use App\Modules\TrustSafety\Actions\Strikes;
use App\Modules\TrustSafety\Actions\SuspendAccount;
use App\Modules\TrustSafety\Contracts\ModerationStats;
use App\Modules\TrustSafety\Contracts\RestrictionReason;
use App\Modules\TrustSafety\Enums\CaseDecision;
use App\Modules\TrustSafety\Enums\CaseSource;
use App\Modules\TrustSafety\Enums\CaseStatus;
use App\Modules\TrustSafety\Exceptions\TrustSafetyException;
use App\Modules\TrustSafety\Models\ModerationCase;
use App\Modules\TrustSafety\Models\Report;
use BackedEnum;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;

/**
 * Canvas board AdminModeration (plan M8a, use cases 25–27 and 29): the queue with tabs
 * by source, oldest first with anything that may involve a minor on top, a detail pane
 * and the decisions. Reporters stay anonymous. Content cases (blurred preview, reveal,
 * remove) arrive with posts in M4; hash-match, AI, consent and leak cases with M4 and M7.
 */
final class ModerationBoard extends Page
{
    private const int PER_PAGE = 25;

    protected static string|BackedEnum|null $navigationIcon = null;

    protected static ?string $navigationLabel = 'Moderation queue';

    protected static ?int $navigationSort = 3;

    protected static ?string $title = 'Moderation queue';

    protected static ?string $slug = 'moderation';

    protected string $view = 'trustsafety::admin.moderation';

    #[Url(except: 'all')]
    public string $tab = 'all';

    #[Url(as: 'case')]
    public ?string $selected = null;

    #[Url(except: 1)]
    public int $page = 1;

    public string $reason = '';

    public string $note = '';

    public int $days = 1;

    public static function canAccess(): bool
    {
        return Gate::allows(StaffAbility::WorkModeration->value);
    }

    public static function getNavigationBadge(): ?string
    {
        $pending = app(ModerationStats::class)->pending();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function getNavigationBadgeTooltip(): string
    {
        return 'Open cases';
    }

    public function getSubheading(): string
    {
        $stats = app(ModerationStats::class);
        $oldest = $stats->oldestPendingSince();
        $urgent = $stats->urgent();

        return number_format($stats->pending()).' open'
            .($oldest instanceof CarbonImmutable ? ' · oldest waiting '.$oldest->diffForHumans(syntax: CarbonInterface::DIFF_ABSOLUTE, short: true) : '')
            .($urgent > 0 ? ' · '.$urgent.' may involve a minor' : '');
    }

    public function setTab(string $tab): void
    {
        $this->tab = $tab === 'all' || CaseSource::tryFrom($tab) instanceof CaseSource ? $tab : 'all';
        $this->page = 1;
        $this->selected = null;
    }

    public function select(string $caseId): void
    {
        $this->selected = $caseId;
        $this->note = '';
        $this->reason = '';
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
        $source = CaseSource::tryFrom($this->tab);

        /** @var array<string, int|string> $raw */
        $raw = ModerationCase::query()->pending()
            ->groupBy('source')
            ->selectRaw('source, count(*) AS n')
            ->pluck('n', 'source')
            ->all();
        $counts = array_map(intval(...), $raw);

        $cases = ModerationCase::query()->pending()
            ->when($source instanceof CaseSource, static fn (Builder $q): Builder => $q->where('source', $source))
            ->orderByDesc('urgent')
            ->orderBy('created_at')
            ->orderBy('id')
            ->paginate(self::PER_PAGE, page: $this->page);

        $detail = $this->detail() ?? $cases->getCollection()->first();
        if ($detail instanceof ModerationCase && $this->selected !== $detail->id) {
            $this->selected = $detail->id;
        }

        $directory = app(AccountDirectory::class);
        $pages = app(CreatorPages::class);
        $accounts = app(Accounts::class);
        $names = [];
        $handles = [];
        foreach ([...$cases->items(), ...($detail instanceof ModerationCase ? [$detail] : [])] as $case) {
            $names[$case->target_user_id] ??= $accounts->username($case->target_user_id);
            if ($case->target_kind === ModerationCase::CREATOR_PAGE) {
                $handles[$case->target_user_id] ??= $pages->handleForUser($case->target_user_id);
            }
        }

        $isAdmin = Gate::allows(StaffAbility::DecideEscalatedCase->value);

        return [
            'tabs' => ['all' => 'All · '.array_sum($counts)] + collect(CaseSource::cases())
                ->mapWithKeys(static fn (CaseSource $s): array => [$s->value => $s->tab().' · '.($counts[$s->value] ?? 0)])
                ->all(),
            'active' => $source instanceof CaseSource ? $source->value : 'all',
            'cases' => $cases,
            'detail' => $detail,
            'names' => $names,
            'handles' => $handles,
            'target' => $detail instanceof ModerationCase ? $directory->find($detail->target_user_id) : null,
            'strikes' => $detail instanceof ModerationCase ? Strikes::activeCount($detail->target_user_id) : 0,
            'otherCases' => $detail instanceof ModerationCase
                ? ModerationCase::query()->where('target_user_id', $detail->target_user_id)->whereKeyNot($detail->id)->count()
                : 0,
            'reportReasons' => $detail instanceof ModerationCase ? $this->reasonCounts($detail) : [],
            'details' => $detail instanceof ModerationCase
                ? $detail->reports()->whereNotNull('details')->latest('created_at')->limit(5)->get()
                : collect(),
            'holder' => $detail instanceof ModerationCase ? $this->holderLabel($detail) : null,
            'decidedBy' => $detail?->decided_by !== null ? $accounts->username($detail->decided_by) : null,
            'canDecide' => $detail instanceof ModerationCase && $detail->isPending() && (! $detail->needsAdmin() || $isAdmin),
            'reasons' => RestrictionReason::options(),
            'defaultReason' => $detail?->reason->restriction()->value,
            'dayOptions' => Gate::allows(StaffAbility::SuspendUserLong->value) ? SuspendAccount::DAYS : [1, 7],
        ];
    }

    /**
     * @return array{text: string, tone: 'danger'|'warning'|'muted'}
     */
    public static function urgency(ModerationCase $case): array
    {
        if ($case->urgent && $case->due_at instanceof CarbonImmutable) {
            return $case->due_at->isPast()
                ? ['text' => 'May involve a minor · overdue', 'tone' => 'danger']
                : ['text' => 'May involve a minor · due in '.$case->due_at->diffForHumans(syntax: CarbonInterface::DIFF_ABSOLUTE, short: true), 'tone' => 'danger'];
        }

        if ($case->status === CaseStatus::Escalated) {
            return ['text' => 'With an admin', 'tone' => 'warning'];
        }

        return ['text' => $case->reason->label(), 'tone' => 'muted'];
    }

    public static function title(ModerationCase $case): string
    {
        return '#'.$case->number.' · '.$case->source->label().($case->report_count > 1 ? ' ×'.$case->report_count : '');
    }

    /**
     * @param  LengthAwarePaginator<int, ModerationCase>  $cases
     */
    public static function pageLabel(LengthAwarePaginator $cases): string
    {
        return 'Page '.$cases->currentPage().' of '.max(1, $cases->lastPage());
    }

    public function assignAction(): Action
    {
        return Action::make('assign')->label('Assign to me')->color('gray')->size('sm')
            ->action(function (): void {
                $this->run(fn (string $staff): ModerationCase => app(AssignCase::class)->assign((string) $this->selected, $staff), 'Assigned to you.', false);
            });
    }

    public function releaseAction(): Action
    {
        return Action::make('release')->label('Unassign')->color('gray')->size('sm')
            ->action(function (): void {
                $this->run(fn (string $staff): ModerationCase => app(AssignCase::class)->release((string) $this->selected, $staff), 'Unassigned.', false);
            });
    }

    public function dismissAction(): Action
    {
        return $this->decision(CaseDecision::NoAction, 'Close the case with no action?', 'Nothing happens to the account. Your note is saved to the audit log.', 'No action', 'gray');
    }

    public function warnAction(): Action
    {
        return $this->decision(CaseDecision::Warn, 'Warn this account?', 'The user gets an email with the reason category, without a strike.', 'Warn', 'gray');
    }

    public function strikeAction(): Action
    {
        return $this->decision(CaseDecision::Strike, 'Give a strike?', 'The user gets an email with the reason category. Three strikes flag the account for a ban decision.', 'Strike', 'gray');
    }

    public function suspendAction(): Action
    {
        return $this->decision(CaseDecision::Suspend, 'Suspend this account?', 'They are signed out everywhere and cannot sign in until the suspension ends.', 'Suspend', 'gray')
            ->label(fn (): string => 'Suspend '.$this->days.' '.($this->days === 1 ? 'day' : 'days'));
    }

    public function escalateAction(): Action
    {
        return $this->decision(CaseDecision::Escalate, 'Escalate to an admin?', 'The case stays in the queue for an admin to decide.', 'Escalate', 'danger')
            ->outlined();
    }

    private function decision(CaseDecision $decision, string $heading, string $description, string $label, string $color): Action
    {
        // Filament finds an action by its method name (warnAction() → "warn"). "No action" is
        // dismissAction(): Filament would not run an action named "noAction".
        return Action::make($decision === CaseDecision::NoAction ? 'dismiss' : $decision->value)
            ->label($decision === CaseDecision::Escalate ? 'Escalate to admin' : $decision->label())
            ->color($color)
            ->requiresConfirmation()
            ->modalHeading($heading)
            ->modalDescription($description)
            ->modalSubmitActionLabel($label)
            ->action(function () use ($decision): void {
                $reason = RestrictionReason::tryFrom($this->reason);
                $this->run(fn (string $staff): ModerationCase => app(DecideCase::class)->handle(
                    (string) $this->selected,
                    $staff,
                    $decision,
                    $this->note,
                    $reason,
                    $decision === CaseDecision::Suspend ? $this->days : null,
                ), $decision === CaseDecision::Escalate ? 'Escalated to an admin.' : 'Decided: '.mb_strtolower($decision->label()).'.');
            });
    }

    private function detail(): ?ModerationCase
    {
        return $this->selected === null ? null : ModerationCase::query()->find($this->selected);
    }

    /**
     * @return array<string, int> reason label => reports
     */
    private function reasonCounts(ModerationCase $case): array
    {
        $counts = [];
        foreach ($case->reports()->get(['reason']) as $report) {
            /** @var Report $report */
            $label = $report->reason->label();
            $counts[$label] = ($counts[$label] ?? 0) + 1;
        }

        return $counts;
    }

    private function holderLabel(ModerationCase $case): string
    {
        if ($case->assigned_to === null) {
            return 'Not assigned';
        }

        if ($case->assigned_to === auth()->id()) {
            return 'Assigned to you';
        }

        $username = app(Accounts::class)->username($case->assigned_to);

        return 'Held by '.($username !== null ? '@'.$username : 'another moderator');
    }

    /**
     * @param  callable(string): ModerationCase  $change
     */
    private function run(callable $change, string $done, bool $clear = true): void
    {
        $staff = auth()->id();

        if (! is_string($staff)) {
            abort(403);
        }

        $this->resetErrorBag();

        try {
            $change($staff);
        } catch (TrustSafetyException $e) {
            $this->addError($e->field, $e->getMessage());
            Notification::make()->title($e->getMessage())->danger()->send();

            return;
        }

        if ($clear) {
            $this->note = '';
            $this->reason = '';
        }

        Notification::make()->title($done)->success()->send();
    }
}
