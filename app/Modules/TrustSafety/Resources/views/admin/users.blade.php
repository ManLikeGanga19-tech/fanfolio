{{-- Canvas board AdminUsers. Spend, disputes and leak matches arrive with M3 and M4 (see UsersBoard). --}}
@php
    use App\Modules\TrustSafety\Filament\Admin\UsersBoard;
    $zone = \Filament\Support\Facades\FilamentTimezone::get();
    $tones = ['ok' => 'text-success', 'warning' => 'text-warning', 'danger' => 'text-danger', 'muted' => 'text-ink-muted'];
@endphp
<x-filament-panels::page>
    <div class="flex flex-col gap-6">
        <div class="flex flex-wrap items-center gap-3">
            <label class="flex h-11 min-w-0 flex-[1_1_260px] items-center gap-2 rounded-md border border-border-strong bg-surface-raised px-3.5 text-ink-muted">
                <span class="sr-only">Search username, email or ID</span>
                <x-ui.icon name="search" class="size-4 flex-none" />
                <input type="search" wire:model.live.debounce.400ms="search" placeholder="Search username, email or ID" class="h-full min-w-0 flex-1 border-0 bg-transparent p-0 text-ink placeholder:text-ink-muted focus:ring-0">
            </label>
            <div role="group" aria-label="Filter users" class="flex flex-wrap gap-2">
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
            <section aria-label="Users" class="min-w-0 flex-[2_1_520px] overflow-x-auto rounded-lg border border-border bg-surface px-3 py-2">
                @if ($accounts->isEmpty())
                    <p class="p-4 text-ink-muted">No accounts match.</p>
                @else
                    <table class="w-full border-collapse text-sm">
                        <thead>
                            <tr class="text-left text-label uppercase tracking-wide text-ink-muted">
                                <th scope="col" class="whitespace-nowrap border-b border-border px-3 py-2.5">User</th>
                                <th scope="col" class="whitespace-nowrap border-b border-border px-3 py-2.5">Role</th>
                                <th scope="col" class="whitespace-nowrap border-b border-border px-3 py-2.5">Age verified</th>
                                <th scope="col" class="whitespace-nowrap border-b border-border px-3 py-2.5">Strikes</th>
                                <th scope="col" class="whitespace-nowrap border-b border-border px-3 py-2.5">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($accounts as $account)
                                @php($pill = UsersBoard::statusPill($account, $strikes[$account->id] ?? 0))
                                <tr wire:key="user-{{ $account->id }}" @class(['bg-surface-raised' => $detail?->id === $account->id])>
                                    <td class="whitespace-nowrap border-b border-border px-3 py-3">
                                        <button type="button" wire:click="select('{{ $account->id }}')" aria-pressed="{{ $detail?->id === $account->id ? 'true' : 'false' }}" class="font-semibold text-ink underline-offset-4 hover:underline">{{ '@'.$account->username }}</button>
                                    </td>
                                    <td class="whitespace-nowrap border-b border-border px-3 py-3">{{ ucfirst($account->role) }}</td>
                                    <td class="whitespace-nowrap border-b border-border px-3 py-3">{{ $account->ageVerified ? 'Yes' : 'No' }}</td>
                                    <td class="whitespace-nowrap border-b border-border px-3 py-3">{{ $strikes[$account->id] ?? 0 }}</td>
                                    <td class="whitespace-nowrap border-b border-border px-3 py-3">
                                        <span class="inline-flex h-6 items-center rounded-pill bg-surface-raised px-2.5 text-label {{ $tones[$pill['tone']] }}">{{ $pill['text'] }}</span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    @if ($accounts->lastPage() > 1)
                        <nav aria-label="Pages" class="flex items-center justify-between gap-3 px-3 py-3 text-small text-ink-muted">
                            <span>{{ UsersBoard::pageLabel($accounts) }}</span>
                            <span class="flex gap-2">
                                <button type="button" wire:click="goToPage({{ $accounts->currentPage() - 1 }})" @disabled($accounts->onFirstPage()) class="inline-flex h-9 items-center rounded-md border border-border px-3 text-ink disabled:opacity-50">Previous</button>
                                <button type="button" wire:click="goToPage({{ $accounts->currentPage() + 1 }})" @disabled(! $accounts->hasMorePages()) class="inline-flex h-9 items-center rounded-md border border-border px-3 text-ink disabled:opacity-50">Next</button>
                            </span>
                        </nav>
                    @endif
                @endif
            </section>

            <section aria-label="User detail" class="flex min-w-0 flex-[1_1_300px] flex-col gap-3 rounded-lg border border-border bg-surface p-6">
                @if ($detail === null)
                    <h2 class="font-display text-h3">User detail</h2>
                    <p class="text-small text-ink-muted">Choose a user to see their account and act on it.</p>
                @else
                    @php($pill = UsersBoard::statusPill($detail, $detailStrikes->count()))
                    <h2 class="font-display text-h3">{{ '@'.$detail->username }}</h2>
                    <dl class="text-sm">
                        <div class="flex justify-between gap-4 border-b border-border py-2"><dt class="text-ink-muted">Joined</dt><dd>{{ $detail->joinedAt->setTimezone($zone)->format('j M Y') }}</dd></div>
                        <div class="flex justify-between gap-4 border-b border-border py-2"><dt class="text-ink-muted">Role</dt><dd>{{ ucfirst($detail->role) }}</dd></div>
                        <div class="flex justify-between gap-4 border-b border-border py-2"><dt class="text-ink-muted">Age verified</dt><dd>{{ $detail->ageVerified ? 'Yes' : 'No' }}</dd></div>
                        <div class="flex justify-between gap-4 border-b border-border py-2"><dt class="text-ink-muted">Active devices</dt><dd>{{ $detail->activeDevices }} of {{ $detail->deviceLimit }}</dd></div>
                        <div class="flex justify-between gap-4 border-b border-border py-2"><dt class="text-ink-muted">Strikes</dt><dd>{{ $detailStrikes->count() }}</dd></div>
                        <div class="flex justify-between gap-4 py-2"><dt class="text-ink-muted">Status</dt><dd class="{{ $tones[$pill['tone']] }}">{{ $pill['text'] }}</dd></div>
                    </dl>

                    @if ($detailStrikes->isNotEmpty())
                        <ul class="flex flex-col gap-1 text-small" aria-label="Active strikes">
                            @foreach ($detailStrikes as $strike)
                                <li wire:key="strike-{{ $strike->id }}" class="flex items-center justify-between gap-3">
                                    <span>{{ $strike->reason->label() }} · {{ $strike->created_at->setTimezone($zone)->format('j M Y') }}</span>
                                    {{ ($this->removeStrikeAction)(['strike' => $strike->id]) }}
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    @if ($detail->isStaff)
                        <p class="text-small text-ink-muted">Staff accounts are managed on the Staff board.</p>
                    @else
                        <div class="flex flex-col gap-1.5">
                            <label for="decision-reason" class="text-sm font-semibold">Reason category</label>
                            <select id="decision-reason" wire:model="reason" class="h-11 rounded-md border border-border-strong bg-surface-raised px-3 text-ink" @error('reason') aria-invalid="true" aria-describedby="decision-reason-error" @enderror>
                                <option value="">Choose…</option>
                                @foreach ($reasons as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('reason')<p id="decision-reason-error" class="text-small text-danger">{{ $message }}</p>@enderror
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <label for="decision-note" class="text-sm font-semibold">Reason (required)</label>
                            <textarea id="decision-note" wire:model="note" rows="3" maxlength="500" class="rounded-md border border-border-strong bg-surface-raised px-3 py-2 text-ink" aria-describedby="decision-note-help @error('note') decision-note-error @enderror" @error('note') aria-invalid="true" @enderror></textarea>
                            <p id="decision-note-help" class="text-small text-ink-muted">Saved to the audit log. The user only sees the category.</p>
                            @error('note')<p id="decision-note-error" class="text-small text-danger">{{ $message }}</p>@enderror
                        </div>
                        <fieldset class="flex flex-wrap items-center gap-3">
                            <legend class="mb-1.5 text-sm font-semibold">Suspend for</legend>
                            @foreach ($dayOptions as $option)
                                <label class="inline-flex min-h-11 items-center gap-2"><input type="radio" wire:model.live="days" value="{{ $option }}" class="size-4"> {{ $option }} {{ $option === 1 ? 'day' : 'days' }}</label>
                            @endforeach
                        </fieldset>
                        @error('days')<p class="text-small text-danger">{{ $message }}</p>@enderror
                        @error('user')<p class="text-small text-danger">{{ $message }}</p>@enderror

                        <div class="flex flex-wrap gap-2.5">
                            {{ $this->banAction }}
                            {{ $this->suspendAction }}
                            {{ $this->liftAction }}
                            {{ $this->strikeAction }}
                            {{ $this->signOutAction }}
                            {{ $this->flagAction }}
                        </div>
                        <p class="text-small text-ink-muted">Banning signs the user out everywhere, stops them signing in and stops a creator page earning.</p>
                    @endif
                @endif
            </section>
        </div>
    </div>
</x-filament-panels::page>
