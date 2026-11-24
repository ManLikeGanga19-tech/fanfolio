{{-- Canvas board AdminApprovals. Face-match score and sanctions screening arrive with the KYC provider (see ApplicationChecks). --}}
@php
    use App\Modules\Creators\Filament\Admin\CreatorApprovals;
    use App\Modules\Creators\Support\ApplicationChecks;
    use App\Modules\Identity\Contracts\Data\DocumentSide;
    $zone = \Filament\Support\Facades\FilamentTimezone::get();
    $tones = ['ok' => 'text-success', 'warning' => 'text-warning', 'danger' => 'text-danger', 'muted' => 'text-ink'];
    $marks = ['ok' => '✓', 'warning' => '!', 'danger' => '✕'];
@endphp
<x-filament-panels::page>
    <div class="flex flex-wrap items-start gap-4">
        <section aria-label="Applicants" class="min-w-0 flex-[1_1_280px] overflow-hidden rounded-lg border border-border bg-surface">
            <div class="border-b border-border p-3">
                <label class="flex h-11 items-center gap-2 rounded-md border border-border-strong bg-surface-raised px-3.5 text-ink-muted">
                    <span class="sr-only">Search page name or handle</span>
                    <x-ui.icon name="search" class="size-4 flex-none" />
                    <input type="search" wire:model.live.debounce.400ms="search" placeholder="Search page name or handle" class="h-full min-w-0 flex-1 border-0 bg-transparent p-0 text-ink placeholder:text-ink-muted focus:ring-0">
                </label>
            </div>
            @if ($queue->isEmpty())
                <p class="p-4 text-ink-muted">{{ trim($search) === '' ? 'No applications waiting.' : 'No applications match.' }}</p>
            @else
                <ul>
                    @foreach ($queue as $applicant)
                        @php
                            $pill = ApplicationChecks::of($applicant)->pill();
                        @endphp
                        <li wire:key="applicant-{{ $applicant->id }}" @class(['border-b border-border' => ! $loop->last])>
                            <button type="button" wire:click="select('{{ $applicant->id }}')" aria-pressed="{{ $detail?->id === $applicant->id ? 'true' : 'false' }}"
                                @class([
                                    'flex w-full flex-col gap-1 px-4 py-3.5 text-left text-ink hover:bg-surface-raised',
                                    'bg-surface-raised' => $detail?->id === $applicant->id,
                                ])>
                                <span class="flex items-center justify-between gap-3">
                                    <strong class="min-w-0 truncate">{{ $applicant->display_name }}</strong>
                                    <span class="inline-flex h-6 flex-none items-center rounded-pill bg-surface-raised px-2.5 text-label {{ $tones[$pill['tone']] }}">{{ $pill['text'] }}</span>
                                </span>
                                <span class="text-small text-ink-muted">{{ CreatorApprovals::categoryLabel($applicant->category) }} · adult: {{ $applicant->is_adult ? 'yes' : 'no' }} · {{ $applicant->submitted_at?->shortRelativeDiffForHumans() }}</span>
                            </button>
                        </li>
                    @endforeach
                </ul>
                @if ($queue->lastPage() > 1)
                    <nav aria-label="Pages" class="flex items-center justify-between gap-3 border-t border-border px-4 py-3 text-small text-ink-muted">
                        <span>{{ CreatorApprovals::pageLabel($queue) }}</span>
                        <span class="flex gap-2">
                            <button type="button" wire:click="goToPage({{ $queue->currentPage() - 1 }})" @disabled($queue->onFirstPage()) class="inline-flex h-9 items-center rounded-md border border-border px-3 text-ink disabled:opacity-50">Previous</button>
                            <button type="button" wire:click="goToPage({{ $queue->currentPage() + 1 }})" @disabled(! $queue->hasMorePages()) class="inline-flex h-9 items-center rounded-md border border-border px-3 text-ink disabled:opacity-50">Next</button>
                        </span>
                    </nav>
                @endif
            @endif
        </section>

        <section aria-label="Applicant detail" class="flex min-w-0 flex-[2_1_480px] flex-col gap-4 rounded-lg border border-border bg-surface p-6">
            @if ($detail === null)
                <h2 class="font-display text-h3">Applicant detail</h2>
                <p class="text-small text-ink-muted">Nothing to review. New applications appear here oldest first.</p>
            @else
                @php
                    $checks = ApplicationChecks::of($detail);
                    $check = $checks->check;
                    $age = $checks->age();
                @endphp
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h2 class="font-display text-h3">{{ $detail->display_name }} · {{ '@'.$detail->handle }}</h2>
                        <p class="text-small text-ink-muted">Submitted {{ $detail->submitted_at?->setTimezone($zone)->format('j M, H:i') }} · {{ CreatorApprovals::documentType($check) }}</p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="inline-flex h-6 items-center rounded-pill bg-surface-raised px-2.5 text-label">{{ $holder }}</span>
                        {{-- Chosen here from fresh data: Filament keeps an action's visibility from before it ran. --}}
                        @if ($detail->assigned_to === auth()->id())
                            {{ $this->releaseAction }}
                        @else
                            {{ $this->assignAction }}
                        @endif
                    </div>
                </div>

                @if ($check !== null)
                    <div class="flex flex-wrap gap-3">
                        <a href="{{ route('creator.kyc-document', ['creator' => $detail->id, 'side' => DocumentSide::Front->value]) }}" target="_blank" rel="noopener"
                            class="flex h-36 flex-[1_1_220px] flex-col items-center justify-center gap-1 rounded-md bg-surface-raised px-4 text-center text-small text-ink-muted hover:text-ink">
                            <x-ui.icon name="eye" class="size-5" />
                            ID document (front) · click to view, logged
                        </a>
                        <a href="{{ route('creator.kyc-document', ['creator' => $detail->id, 'side' => DocumentSide::SelfieFrame->value]) }}" target="_blank" rel="noopener"
                            class="flex h-36 flex-[1_1_160px] flex-col items-center justify-center gap-1 rounded-md bg-surface-raised px-4 text-center text-small text-ink-muted hover:text-ink">
                            <x-ui.icon name="eye" class="size-5" />
                            Selfie frame · click to view, logged
                        </a>
                    </div>
                    @if ($check->id_type !== 'passport')
                        <p class="-mt-2 text-small"><a href="{{ route('creator.kyc-document', ['creator' => $detail->id, 'side' => DocumentSide::Back->value]) }}" target="_blank" rel="noopener" class="text-brand underline-offset-4 hover:underline">View the back of the ID (logged)</a></p>
                    @endif
                @endif

                <dl class="grid grid-cols-[repeat(auto-fit,minmax(150px,1fr))] gap-3">
                    <div class="rounded-md bg-surface-raised p-3">
                        <dt class="text-small text-ink-muted">Face match (partner)</dt>
                        <dd class="font-semibold {{ $check?->liveness_passed ? 'text-success' : 'text-danger' }}">{{ $check?->liveness_passed ? 'Selfie matches ID' : 'Not matched' }}</dd>
                    </div>
                    <div class="rounded-md bg-surface-raised p-3">
                        <dt class="text-small text-ink-muted">Date of birth</dt>
                        <dd class="font-semibold">
                            @if ($revealedDob !== null)
                                {{ $revealedDob }} · age {{ $age }}
                            @elseif ($age !== null)
                                Age {{ $age }}
                            @else
                                Not given
                            @endif
                        </dd>
                        @if ($age !== null && $revealedDob === null)
                            <dd class="text-small">{{ $this->revealDobAction }}</dd>
                        @endif
                    </div>
                    <div class="rounded-md bg-surface-raised p-3">
                        <dt class="text-small text-ink-muted">ID number</dt>
                        <dd class="font-semibold">{{ $check?->document_last4 !== null ? '••••'.$check->document_last4 : 'Not given' }}</dd>
                    </div>
                    <div class="rounded-md bg-surface-raised p-3">
                        <dt class="text-small text-ink-muted">Payout account</dt>
                        <dd class="font-semibold">{{ $checks->payout?->display_hint ?? 'None' }}</dd>
                        <dd class="text-small {{ $checks->payoutNameMatches() ? 'text-success' : 'text-warning' }}">{{ $checks->payoutNameMatches() ? 'Name matches ID' : 'Name does not match ID' }}</dd>
                    </div>
                </dl>

                <ul aria-label="Checks">
                    @foreach ($checks->lines() as $line)
                        <li @class(['flex items-center gap-2.5 py-2 text-sm', 'border-b border-border' => ! $loop->last])>
                            <span aria-hidden="true" class="w-4 flex-none text-center font-semibold {{ $tones[$line['tone']] }}">{{ $marks[$line['tone']] }}</span>
                            <span>{{ $line['text'] }}</span>
                        </li>
                    @endforeach
                </ul>

                <p class="text-small text-ink-muted">{{ CreatorApprovals::categoryLabel($detail->category) }} · adult content: {{ $detail->is_adult ? 'yes' : 'no' }}</p>

                <div class="flex flex-wrap gap-2.5">
                    {{ $this->approveAction }}
                    {{ $this->requestChangesAction }}
                    {{ $this->rejectAction }}
                </div>
                <p class="text-small text-ink-muted">Every decision and every document view is written to the audit log with your name.</p>
            @endif
        </section>
    </div>
</x-filament-panels::page>
