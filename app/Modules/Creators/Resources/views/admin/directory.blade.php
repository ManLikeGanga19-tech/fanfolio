{{-- Creators directory (plan M8a use cases 9–12). No canvas board: follows AdminUsers. See CreatorsDirectory. --}}
@php
    use App\Modules\Creators\Enums\CreatorStatus;
    use App\Modules\Creators\Filament\Admin\CreatorApprovals;
    use App\Modules\Creators\Filament\Admin\CreatorsDirectory;
    use App\Modules\Identity\Contracts\Data\VerificationStatus;
    $zone = \Filament\Support\Facades\FilamentTimezone::get();
    $tones = ['ok' => 'text-success', 'warning' => 'text-warning', 'danger' => 'text-danger', 'muted' => 'text-ink-muted'];
    $row = 'flex justify-between gap-4 border-b border-border py-2';
@endphp
<x-filament-panels::page>
    <div class="flex flex-col gap-6">
        <div class="flex flex-wrap items-center gap-3">
            <label class="flex h-11 min-w-0 flex-[1_1_260px] items-center gap-2 rounded-md border border-border-strong bg-surface-raised px-3.5 text-ink-muted">
                <span class="sr-only">Search page name or handle</span>
                <x-ui.icon name="search" class="size-4 flex-none" />
                <input type="search" wire:model.live.debounce.400ms="search" placeholder="Search page name or handle" class="h-full min-w-0 flex-1 border-0 bg-transparent p-0 text-ink placeholder:text-ink-muted focus:ring-0">
            </label>
            <div role="group" aria-label="Filter creators" class="flex flex-wrap gap-2">
                @foreach ($filters as $key => $label)
                    <button type="button" wire:click="setFilter('{{ $key }}')" aria-pressed="{{ $active === $key ? 'true' : 'false' }}"
                        @class([
                            'inline-flex h-9 items-center rounded-pill border px-3.5 text-small',
                            'border-brand bg-surface-raised font-semibold text-ink' => $active === $key,
                            'border-border bg-bg text-ink-muted hover:text-ink' => $active !== $key,
                        ])>{{ $label }}</button>
                @endforeach
            </div>
        </div>

        <div class="flex flex-wrap items-start gap-4">
            <section aria-label="Creators" class="min-w-0 flex-[2_1_520px] overflow-x-auto rounded-lg border border-border bg-surface px-3 py-2">
                @if ($creators->isEmpty())
                    <p class="p-4 text-ink-muted">No creators match.</p>
                @else
                    <table class="w-full border-collapse text-sm">
                        <thead>
                            <tr class="text-left text-label uppercase tracking-wide text-ink-muted">
                                <th scope="col" class="whitespace-nowrap border-b border-border px-3 py-2.5">Page</th>
                                <th scope="col" class="whitespace-nowrap border-b border-border px-3 py-2.5">Category</th>
                                <th scope="col" class="whitespace-nowrap border-b border-border px-3 py-2.5">18+</th>
                                <th scope="col" class="whitespace-nowrap border-b border-border px-3 py-2.5">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($creators as $creator)
                                @php
                                    $pill = CreatorsDirectory::statusPill($creator);
                                @endphp
                                <tr wire:key="creator-{{ $creator->id }}" @class(['bg-surface-raised' => $detail?->id === $creator->id])>
                                    <td class="whitespace-nowrap border-b border-border px-3 py-3">
                                        <button type="button" wire:click="select('{{ $creator->id }}')" aria-pressed="{{ $detail?->id === $creator->id ? 'true' : 'false' }}" class="text-left underline-offset-4 hover:underline">
                                            <span class="block font-semibold text-ink">{{ $creator->display_name }}</span>
                                            <span class="block text-small text-ink-muted">{{ '@'.$creator->handle }}</span>
                                        </button>
                                    </td>
                                    <td class="whitespace-nowrap border-b border-border px-3 py-3">{{ CreatorApprovals::categoryLabel($creator->category) }}</td>
                                    <td class="whitespace-nowrap border-b border-border px-3 py-3">{{ $creator->is_adult ? 'Yes' : 'No' }}</td>
                                    <td class="whitespace-nowrap border-b border-border px-3 py-3">
                                        <span class="inline-flex h-6 items-center rounded-pill bg-surface-raised px-2.5 text-label {{ $tones[$pill['tone']] }}">{{ $pill['text'] }}</span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    @if ($creators->lastPage() > 1)
                        <nav aria-label="Pages" class="flex items-center justify-between gap-3 px-3 py-3 text-small text-ink-muted">
                            <span>{{ CreatorsDirectory::pageLabel($creators) }}</span>
                            <span class="flex gap-2">
                                <button type="button" wire:click="goToPage({{ $creators->currentPage() - 1 }})" @disabled($creators->onFirstPage()) class="inline-flex h-9 items-center rounded-md border border-border px-3 text-ink disabled:opacity-50">Previous</button>
                                <button type="button" wire:click="goToPage({{ $creators->currentPage() + 1 }})" @disabled(! $creators->hasMorePages()) class="inline-flex h-9 items-center rounded-md border border-border px-3 text-ink disabled:opacity-50">Next</button>
                            </span>
                        </nav>
                    @endif
                @endif
            </section>

            <section aria-label="Creator detail" class="flex min-w-0 flex-[1_1_320px] flex-col gap-4 rounded-lg border border-border bg-surface p-6">
                @if ($detail === null)
                    <h2 class="font-display text-h3">Creator detail</h2>
                    <p class="text-small text-ink-muted">Choose a creator to see their page, ID checks and payout methods, and act on them.</p>
                @else
                    @php
                        $pill = CreatorsDirectory::statusPill($detail);
                    @endphp
                    <div>
                        <h2 class="font-display text-h3">{{ $detail->display_name }} · {{ '@'.$detail->handle }}</h2>
                        <p class="text-small text-ink-muted">Account {{ $username !== null ? '@'.$username : 'unknown' }} · {{ CreatorApprovals::categoryLabel($detail->category) }} · adult content: {{ $detail->is_adult ? 'yes' : 'no' }}</p>
                    </div>

                    <dl class="text-sm">
                        <div class="{{ $row }}"><dt class="text-ink-muted">Status</dt><dd class="{{ $tones[$pill['tone']] }}">{{ $pill['text'] }}</dd></div>
                        @if ($accountRestricted)
                            <div class="{{ $row }}"><dt class="text-ink-muted">Account</dt><dd class="text-danger">Suspended or banned</dd></div>
                        @endif
                        <div class="{{ $row }}"><dt class="text-ink-muted">Applied</dt><dd>{{ $detail->created_at?->setTimezone($zone)->format('j M Y') ?? '—' }}</dd></div>
                        <div class="{{ $row }}"><dt class="text-ink-muted">Submitted</dt><dd>{{ $detail->submitted_at?->setTimezone($zone)->format('j M Y') ?? '—' }}</dd></div>
                        <div class="{{ $row }}"><dt class="text-ink-muted">Approved</dt><dd>{{ $detail->approved_at?->setTimezone($zone)->format('j M Y') ?? '—' }}</dd></div>
                        @if ($detail->suspended_at !== null)
                            <div class="{{ $row }}"><dt class="text-ink-muted">Suspended</dt><dd>{{ $detail->suspended_at->setTimezone($zone)->format('j M Y, H:i') }}</dd></div>
                        @endif
                        <div class="flex justify-between gap-4 py-2"><dt class="text-ink-muted">ID valid until</dt><dd>{{ $detail->id_expires_at?->setTimezone($zone)->format('j M Y') ?? 'No expiry date' }}</dd></div>
                    </dl>

                    <div class="flex flex-col gap-2">
                        <h3 class="text-sm font-semibold">ID checks</h3>
                        @forelse ($checks as $check)
                            <p wire:key="kyc-{{ $check->id }}" class="text-small">
                                <span @class(['font-semibold', 'text-success' => $check->status === VerificationStatus::Passed, 'text-warning' => $check->status === VerificationStatus::Review, 'text-danger' => $check->status === VerificationStatus::Failed, 'text-ink-muted' => $check->status === VerificationStatus::Pending])>{{ ucfirst($check->status->value) }}</span>
                                · {{ $check->created_at?->setTimezone($zone)->format('j M Y') }}
                                · {{ CreatorApprovals::documentType($check) }}{{ $check->document_last4 !== null ? ' ••••'.$check->document_last4 : '' }}
                                @if ($check->failure_reason !== null) · {{ str_replace('_', ' ', $check->failure_reason) }} @endif
                            </p>
                        @empty
                            <p class="text-small text-ink-muted">None yet.</p>
                        @endforelse
                    </div>

                    <div class="flex flex-col gap-2">
                        <h3 class="text-sm font-semibold">Payout methods</h3>
                        @forelse ($payoutMethods as $method)
                            <p wire:key="payout-{{ $method->id }}" class="text-small">
                                {{ $method->display_hint }}
                                · {{ $method->name_matches_kyc ? 'name matches ID' : 'name does not match ID' }}
                                @if ($method->revoked_at !== null) · <span class="text-ink-muted">removed</span> @elseif ($method->is_default) · <span class="text-success">in use</span> @endif
                            </p>
                        @empty
                            <p class="text-small text-ink-muted">None yet.</p>
                        @endforelse
                    </div>

                    @if ($isOwn)
                        <p class="text-small text-ink-muted">This is your own creator page. Another staff member must act on it.</p>
                    @elseif ($detail->status === CreatorStatus::Active || $detail->status === CreatorStatus::Suspended)
                        @if ($detail->status === CreatorStatus::Active && ($can['suspend'] || $can['pause']))
                            <div class="flex flex-col gap-1.5">
                                <label for="creator-reason" class="text-sm font-semibold">Reason category</label>
                                <select id="creator-reason" wire:model="reason" class="h-11 rounded-md border border-border-strong bg-surface-raised px-3 text-ink" @error('reason') aria-invalid="true" aria-describedby="creator-reason-error" @enderror>
                                    <option value="">Choose…</option>
                                    @foreach ($reasons as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                                @error('reason')<p id="creator-reason-error" class="text-small text-danger">{{ $message }}</p>@enderror
                            </div>
                        @endif
                        <div class="flex flex-col gap-1.5">
                            <label for="creator-note" class="text-sm font-semibold">Note (required)</label>
                            <textarea id="creator-note" wire:model="note" rows="3" maxlength="500" class="rounded-md border border-border-strong bg-surface-raised px-3 py-2 text-ink" aria-describedby="creator-note-help @error('note') creator-note-error @enderror" @error('note') aria-invalid="true" @enderror></textarea>
                            <p id="creator-note-help" class="text-small text-ink-muted">Saved to the audit log. The creator only sees the category.</p>
                            @error('note')<p id="creator-note-error" class="text-small text-danger">{{ $message }}</p>@enderror
                        </div>
                        @if ($detail->status === CreatorStatus::Active && $can['pause'])
                            <fieldset class="flex flex-wrap items-center gap-3">
                                <legend class="mb-1.5 text-sm font-semibold">Pause for</legend>
                                @foreach ([1, 3, 7] as $option)
                                    <label class="inline-flex min-h-11 items-center gap-2"><input type="radio" wire:model.live="days" value="{{ $option }}" class="size-4"> {{ $option }} {{ $option === 1 ? 'day' : 'days' }}</label>
                                @endforeach
                            </fieldset>
                            @error('days')<p class="text-small text-danger">{{ $message }}</p>@enderror
                        @endif
                        @error('status')<p class="text-small text-danger">{{ $message }}</p>@enderror
                        @error('staff')<p class="text-small text-danger">{{ $message }}</p>@enderror

                        {{-- Chosen here from fresh data: Filament keeps an action's visibility from before it ran. --}}
                        <div class="flex flex-wrap gap-2.5">
                            @if ($detail->status === CreatorStatus::Active)
                                @if ($can['suspend']) {{ $this->suspendAction }} @endif
                                @if ($can['pause']) {{ $this->pauseAction }} @endif
                                @if ($can['recheck']) {{ $this->recheckAction }} @endif
                            @elseif ($can['reinstate'])
                                {{ $this->reinstateAction }}
                            @endif
                        </div>
                        @if ($detail->status === CreatorStatus::Suspended && ! $can['reinstate'])
                            <p class="text-small text-ink-muted">Only an admin can reinstate a suspended page. A pause ends by itself.</p>
                        @endif
                    @else
                        <p class="text-small text-ink-muted">Applications are decided on Creator approvals.</p>
                    @endif
                @endif
            </section>
        </div>
    </div>
</x-filament-panels::page>
