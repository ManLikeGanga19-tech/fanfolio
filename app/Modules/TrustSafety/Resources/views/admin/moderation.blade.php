{{-- Canvas board AdminModeration. Content cases (blurred preview, reveal, remove) arrive with M4; see ModerationBoard. --}}
@php
    use App\Modules\TrustSafety\Enums\CaseStatus;
    use App\Modules\TrustSafety\Filament\Admin\ModerationBoard;
    use App\Modules\TrustSafety\Models\ModerationCase;
    $zone = \Filament\Support\Facades\FilamentTimezone::get();
    $tones = ['ok' => 'text-success', 'warning' => 'text-warning', 'danger' => 'text-danger', 'muted' => 'text-ink-muted'];
    $dots = ['danger' => 'bg-danger', 'warning' => 'bg-warning', 'muted' => 'bg-border-strong'];
@endphp
<x-filament-panels::page>
    <div class="flex flex-col gap-5">
        <div role="group" aria-label="Queue filter" class="flex flex-wrap gap-2">
            @foreach ($tabs as $key => $label)
                <button type="button" wire:click="setTab('{{ $key }}')" aria-pressed="{{ $active === $key ? 'true' : 'false' }}"
                    @class([
                        'inline-flex h-9 items-center rounded-pill border px-3.5 text-small',
                        'border-brand bg-surface-raised font-semibold text-ink' => $active === $key,
                        'border-border bg-bg text-ink-muted hover:text-ink' => $active !== $key,
                    ])>{{ $label }}</button>
            @endforeach
        </div>

        <div class="flex flex-wrap items-start gap-4">
            <section aria-label="Cases" class="min-w-0 flex-[1_1_280px] overflow-hidden rounded-lg border border-border bg-surface">
                @if ($cases->isEmpty())
                    <p class="p-4 text-ink-muted">No open cases here.</p>
                @else
                    <ul>
                        @foreach ($cases as $case)
                            @php
                                $urgency = ModerationBoard::urgency($case);
                                $who = $case->target_kind === ModerationCase::CREATOR_PAGE && ($handles[$case->target_user_id] ?? null) !== null
                                    ? '@'.$handles[$case->target_user_id].' · creator page'
                                    : '@'.($names[$case->target_user_id] ?? 'unknown').' · account';
                            @endphp
                            <li wire:key="case-{{ $case->id }}" @class(['border-b border-border' => ! $loop->last])>
                                <button type="button" wire:click="select('{{ $case->id }}')" aria-pressed="{{ $detail?->id === $case->id ? 'true' : 'false' }}"
                                    @class(['flex w-full flex-col gap-1 px-4 py-3.5 text-left text-ink hover:bg-surface-raised', 'bg-surface-raised' => $detail?->id === $case->id])>
                                    <span class="flex items-center justify-between gap-3">
                                        <strong>{{ ModerationBoard::title($case) }}</strong>
                                        <span class="size-2.5 flex-none rounded-full {{ $dots[$urgency['tone']] }}" aria-hidden="true"></span>
                                    </span>
                                    <span class="text-small text-ink-muted">{{ $who }} · {{ $case->created_at->shortRelativeDiffForHumans() }}</span>
                                    <span class="text-small {{ $tones[$urgency['tone']] }}">{{ $urgency['text'] }}</span>
                                </button>
                            </li>
                        @endforeach
                    </ul>
                    @if ($cases->lastPage() > 1)
                        <nav aria-label="Pages" class="flex items-center justify-between gap-3 border-t border-border px-4 py-3 text-small text-ink-muted">
                            <span>{{ ModerationBoard::pageLabel($cases) }}</span>
                            <span class="flex gap-2">
                                <button type="button" wire:click="goToPage({{ $cases->currentPage() - 1 }})" @disabled($cases->onFirstPage()) class="inline-flex h-9 items-center rounded-md border border-border px-3 text-ink disabled:opacity-50">Previous</button>
                                <button type="button" wire:click="goToPage({{ $cases->currentPage() + 1 }})" @disabled(! $cases->hasMorePages()) class="inline-flex h-9 items-center rounded-md border border-border px-3 text-ink disabled:opacity-50">Next</button>
                            </span>
                        </nav>
                    @endif
                @endif
            </section>

            <section aria-label="Case detail" class="flex min-w-0 flex-[2_1_460px] flex-col gap-4 rounded-lg border border-border bg-surface p-6">
                @if ($detail === null)
                    <h2 class="font-display text-h3">Case detail</h2>
                    <p class="text-small text-ink-muted">Nothing to review. New cases appear here, anything that may involve a minor first.</p>
                @else
                    @php
                        $urgency = ModerationBoard::urgency($detail);
                    @endphp
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h2 class="font-display text-h3">Case {{ ModerationBoard::title($detail) }}</h2>
                        @if ($detail->isPending())
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="inline-flex h-6 items-center rounded-pill bg-surface-raised px-2.5 text-label">{{ $holder }}</span>
                                {{-- Chosen here from fresh data: Filament keeps an action's visibility from before it ran. --}}
                                @if ($detail->assigned_to === auth()->id())
                                    {{ $this->releaseAction }}
                                @else
                                    {{ $this->assignAction }}
                                @endif
                            </div>
                        @endif
                    </div>

                    <div class="grid grid-cols-[repeat(auto-fit,minmax(170px,1fr))] gap-3">
                        <div class="rounded-md bg-surface-raised p-3">
                            <p class="text-small text-ink-muted">Reason</p>
                            <p class="font-semibold {{ $tones[$urgency['tone']] }}">{{ $detail->reason->label() }}</p>
                            @if ($detail->urgent)<p class="text-small text-danger">{{ $urgency['text'] }}</p>@endif
                        </div>
                        <div class="rounded-md bg-surface-raised p-3">
                            <p class="text-small text-ink-muted">{{ $detail->target_kind === ModerationCase::CREATOR_PAGE ? 'Creator page' : 'Account' }}</p>
                            <p class="font-semibold">
                                {{ '@'.($target?->username ?? 'unknown') }}@if (($handles[$detail->target_user_id] ?? null) !== null) · page {{ '@'.$handles[$detail->target_user_id] }}@endif
                            </p>
                            <p class="text-small text-ink-muted">{{ $target !== null ? ucfirst($target->role).' · '.($target->ageVerified ? 'age verified' : 'age not verified').' · '.$strikes.' '.($strikes === 1 ? 'strike' : 'strikes') : 'Account not found' }}</p>
                        </div>
                        <div class="rounded-md bg-surface-raised p-3">
                            <p class="text-small text-ink-muted">{{ $detail->source === \App\Modules\TrustSafety\Enums\CaseSource::StaffFlag ? 'Flags' : 'Reports' }}</p>
                            <p class="font-semibold">{{ $detail->source === \App\Modules\TrustSafety\Enums\CaseSource::StaffFlag ? 'Flagged by staff' : $detail->report_count.' '.($detail->report_count === 1 ? 'person' : 'people') }}</p>
                            <p class="text-small text-ink-muted">{{ $otherCases }} other {{ $otherCases === 1 ? 'case' : 'cases' }} on this account</p>
                        </div>
                    </div>

                    @if ($reportReasons !== [])
                        <ul class="flex flex-wrap gap-2" aria-label="What reporters chose">
                            @foreach ($reportReasons as $label => $count)
                                <li class="inline-flex h-7 items-center rounded-pill bg-surface-raised px-3 text-small">{{ $label }} · {{ $count }}</li>
                            @endforeach
                        </ul>
                    @endif

                    @if ($details->isNotEmpty())
                        <div class="flex flex-col gap-2">
                            <h3 class="text-sm font-semibold">What they wrote</h3>
                            @foreach ($details as $report)
                                <blockquote wire:key="report-{{ $report->id }}" class="rounded-md border-l-2 border-border-strong bg-bg px-3 py-2 text-small">
                                    {{ $report->details }}
                                    <span class="mt-1 block text-ink-muted">{{ $report->created_at->setTimezone($zone)->format('j M, H:i') }}</span>
                                </blockquote>
                            @endforeach
                        </div>
                    @endif
                    <p class="text-small text-ink-muted">Reporters stay anonymous. Hash-match cases are never shown to moderators; they go straight to escalation.</p>

                    @if (! $detail->isPending())
                        <div class="rounded-md bg-surface-raised p-4 text-sm">
                            <p class="font-semibold">Decided: {{ $detail->decision?->label() }}</p>
                            <p class="text-small text-ink-muted">{{ $decidedBy !== null ? '@'.$decidedBy : 'Staff' }} · {{ $detail->decided_at?->setTimezone($zone)->format('j M Y, H:i') }}</p>
                            <p class="mt-2">{{ $detail->decision_note }}</p>
                        </div>
                    @else
                        <div class="flex flex-col gap-1.5">
                            <label for="case-reason" class="text-sm font-semibold">Reason category (for warn, strike and suspend)</label>
                            <select id="case-reason" wire:model="reason" class="h-11 rounded-md border border-border-strong bg-surface-raised px-3 text-ink">
                                <option value="">{{ $reasons[$defaultReason] ?? 'Choose…' }} (from the case)</option>
                                @foreach ($reasons as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <label for="case-note" class="text-sm font-semibold">Decision note (saved to audit log)</label>
                            <textarea id="case-note" wire:model="note" rows="3" maxlength="1000" class="rounded-md border border-border-strong bg-surface-raised px-3 py-2 text-ink" aria-describedby="case-note-help @error('note') case-note-error @enderror" @error('note') aria-invalid="true" @enderror></textarea>
                            <p id="case-note-help" class="text-small text-ink-muted">The user only sees the category, never this note.</p>
                            @error('note')<p id="case-note-error" class="text-small text-danger">{{ $message }}</p>@enderror
                        </div>

                        @if ($canDecide)
                            <fieldset class="flex flex-wrap items-center gap-3">
                                <legend class="mb-1.5 text-sm font-semibold">Suspend for</legend>
                                @foreach ($dayOptions as $option)
                                    <label class="inline-flex min-h-11 items-center gap-2"><input type="radio" wire:model.live="days" value="{{ $option }}" class="size-4"> {{ $option }} {{ $option === 1 ? 'day' : 'days' }}</label>
                                @endforeach
                            </fieldset>
                        @endif
                        @error('case')<p class="text-small text-danger">{{ $message }}</p>@enderror
                        @error('days')<p class="text-small text-danger">{{ $message }}</p>@enderror
                        @error('user')<p class="text-small text-danger">{{ $message }}</p>@enderror
                        @error('staff')<p class="text-small text-danger">{{ $message }}</p>@enderror

                        <div class="flex flex-wrap gap-2.5">
                            @if ($canDecide)
                                {{ $this->dismissAction }}
                                {{ $this->warnAction }}
                                {{ $this->strikeAction }}
                                {{ $this->suspendAction }}
                            @endif
                            @if ($detail->status !== CaseStatus::Escalated)
                                {{ $this->escalateAction }}
                            @endif
                        </div>
                        @if (! $canDecide)
                            <p class="text-small text-warning">Only an admin can decide this case.{{ $detail->status !== CaseStatus::Escalated ? ' Escalate it so an admin sees it.' : '' }}</p>
                        @endif
                    @endif
                @endif
            </section>
        </div>
    </div>
</x-filament-panels::page>
