{{-- Canvas board AdminSettings (see App\Modules\Identity\Filament\Admin\SettingsBoard). Locked values come from the brief; the rest change with a second admin's approval. --}}
@php
    use App\Modules\Identity\Actions\SettingValues;
    use App\Modules\Identity\Filament\Admin\SettingsBoard;
    $zone = \Filament\Support\Facades\FilamentTimezone::get();
    $field = 'flex flex-col gap-2 text-sm font-semibold';
    $box = 'flex h-12 items-center gap-1.5 rounded-md border border-border-strong bg-surface-raised px-3.5 font-normal text-ink';
    $input = 'h-12 w-full rounded-md border border-border-strong bg-surface-raised px-3.5 font-normal text-ink disabled:opacity-60';
    $lock = 'text-small font-normal text-ink-muted';
    $switch = 'relative flex h-6 w-11 shrink-0 items-center rounded-full bg-border-strong p-[3px] transition-colors peer-checked:bg-brand peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-focus peer-disabled:opacity-60 after:size-[18px] after:rounded-full after:bg-ink after:transition-transform peer-checked:after:translate-x-5';
    $rows = [
        'crypto' => ['Crypto top-ups (USDT)', 'Via the crypto gateway. Off: fans pay by card only.'],
        'vpnStaff' => ['VPN at staff sign-in', 'Refuse a detected VPN or proxy when staff sign in (D-16).'],
        'vpnKyc' => ['VPN for creator ID checks', 'Refuse a detected VPN or proxy when a creator starts an ID check (D-16).'],
        'vpnPayouts' => ['VPN for withdrawals', 'Refuse a detected VPN or proxy for withdrawals and payout-method changes (D-16).'],
        'vpnTopUps' => ['VPN for top-ups', 'Refuse a detected VPN or proxy for top-ups (D-16).'],
    ];
@endphp
<x-filament-panels::page>
    <div class="ff-admin flex flex-col gap-6">
        @foreach ($pending as $proposal)
            <div role="status" wire:key="pending-{{ $proposal->id }}" class="flex flex-wrap items-center gap-3 rounded-lg border border-warning bg-surface px-4 py-3.5">
                <span class="inline-flex h-6 items-center rounded-pill bg-surface-raised px-2.5 text-label text-warning">Pending</span>
                <span class="min-w-60 flex-1 text-sm">
                    {{ '@'.($names[$proposal->proposed_by] ?? 'unknown') }} proposed: {{ mb_strtolower($proposal->key->label()) }} {{ SettingValues::describe($proposal->key, $proposal->current_value) }} → {{ SettingValues::describe($proposal->key, $proposal->proposed_value) }}.
                    {{ $proposal->proposed_by === $me ? 'Waiting for another admin.' : 'Waiting for your approval.' }}
                    <span class="block text-small text-ink-muted">Why: {{ $proposal->reason }} · {{ $proposal->proposed_at->setTimezone($zone)->format('j M, H:i') }}</span>
                </span>
                @if ($canPropose)
                    <span class="flex gap-2">
                        @if ($proposal->proposed_by === $me)
                            {{ ($this->withdrawAction)(['proposal' => $proposal->id]) }}
                        @else
                            {{ ($this->approveAction)(['proposal' => $proposal->id]) }}
                            {{ ($this->rejectAction)(['proposal' => $proposal->id]) }}
                        @endif
                    </span>
                @endif
            </div>
        @endforeach
        @error('proposal')<p class="text-small text-danger" role="alert">{{ $message }}</p>@enderror
        @error('staff')<p class="text-small text-danger" role="alert">{{ $message }}</p>@enderror

        <section aria-labelledby="fees-heading" class="flex flex-col gap-4 rounded-lg border border-border bg-surface p-6">
            <h2 id="fees-heading" class="font-display text-h3">Fees &amp; payouts</h2>
            <div class="grid grid-cols-[repeat(auto-fit,minmax(200px,1fr))] gap-4">
                <div class="{{ $field }}">Platform fee<span class="{{ $box }}">{{ $locked['fee'] }}</span><span class="{{ $lock }}">Locked by the brief</span></div>
                <div class="{{ $field }}">Earnings hold<span class="{{ $box }}">{{ $locked['hold'] }}</span><span class="{{ $lock }}">Locked by the brief</span></div>
                <label class="{{ $field }}">Minimum withdrawal
                    <span class="relative"><span class="pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 font-normal text-ink-muted" aria-hidden="true">$</span>
                        <input type="text" inputmode="decimal" wire:model="payoutMinimum" @disabled(! $canPropose) aria-describedby="minimum-hint" class="{{ $input }} pl-7"></span>
                    <span id="minimum-hint" class="{{ $lock }}">Needs a second admin</span>
                    @error('money.payout_min_minor')<span class="text-small font-normal text-danger" role="alert">{{ $message }}</span>@enderror
                </label>
                <div class="{{ $field }}">Manual review above<span class="{{ $box }}">{{ $locked['review'] }}</span><span class="{{ $lock }}">Locked by the brief</span></div>
            </div>
        </section>

        <section aria-labelledby="credits-heading" class="flex flex-col gap-4 rounded-lg border border-border bg-surface p-6">
            <h2 id="credits-heading" class="font-display text-h3">Credits &amp; top-ups</h2>
            <div class="grid grid-cols-[repeat(auto-fit,minmax(200px,1fr))] gap-4">
                <div class="{{ $field }}">Credit value<span class="{{ $box }}">{{ $locked['credits'] }}</span><span class="{{ $lock }}">Locked by the brief</span></div>
                <label class="{{ $field }}">Top-up packs (USD)
                    <input type="text" wire:model="packAmounts" @disabled(! $canPropose) aria-describedby="packs-hint" class="{{ $input }}">
                    <span id="packs-hint" class="{{ $lock }}">Whole dollars, smallest first</span>
                </label>
                <label class="{{ $field }}">Bonus per pack (%)
                    <input type="text" wire:model="packBonuses" @disabled(! $canPropose) aria-describedby="bonus-hint" class="{{ $input }}">
                    <span id="bonus-hint" class="{{ $lock }}">One per pack, 0 for none</span>
                </label>
            </div>
            @error('topup.packs')<p class="text-small text-danger" role="alert">{{ $message }}</p>@enderror
        </section>

        <section aria-labelledby="safety-heading" class="flex flex-col gap-1 rounded-lg border border-border bg-surface p-6">
            <h2 id="safety-heading" class="mb-2 font-display text-h3">Safety &amp; features</h2>
            <div class="flex items-center gap-3 border-b border-border py-2.5">
                <div class="flex-1"><p class="font-semibold">Adult content</p><p class="text-small text-ink-muted">Allowed for verified creators; shown only to age-verified fans. Locked by the brief.</p></div>
                <span class="text-sm">Allowed</span>
            </div>
            @foreach ($rows as $key => [$label, $hint])
                <label class="flex cursor-pointer items-center gap-3 border-b border-border py-2.5" wire:key="switch-{{ $key }}">
                    <span class="flex-1"><span class="block font-semibold">{{ $label }}</span><span id="hint-{{ $key }}" class="block text-small text-ink-muted">{{ $hint }}</span></span>
                    <input type="checkbox" role="switch" wire:model="switches.{{ $key }}" @disabled(! $canPropose) aria-describedby="hint-{{ $key }}" class="peer sr-only">
                    <span class="{{ $switch }}" aria-hidden="true"></span>
                </label>
            @endforeach
            <div class="flex items-center gap-3 border-b border-border py-2.5">
                <div class="flex-1"><p class="font-semibold">Devices per account</p><p class="text-small text-ink-muted">Active devices at once; a new device beyond this is refused at sign-in until one is signed out (D-03). Locked by the brief.</p></div>
                <span class="{{ $box }} h-10 w-20 justify-center">{{ $locked['devices'] }}</span>
            </div>
            <div class="flex items-center gap-3 py-2.5">
                <div class="flex-1"><p class="font-semibold">Live streaming</p><p class="text-small text-ink-muted">Phase 3</p></div>
                <span class="text-sm text-ink-muted">Off</span>
            </div>
        </section>

        @if ($canPropose)
            <p class="text-small text-ink-muted">Edit any of the settings above, then use Request approval. Each change waits for another admin.</p>
        @endif

        <section aria-labelledby="history-heading" class="flex flex-col gap-3 rounded-lg border border-border bg-surface p-6">
            <h2 id="history-heading" class="font-display text-h3">Recent decisions</h2>
            @if ($history->isEmpty())
                <p class="text-small text-ink-muted">No setting has been changed yet.</p>
            @else
                <ul class="flex flex-col">
                    @foreach ($history as $proposal)
                        <li class="border-b border-border py-2.5 text-sm last:border-b-0" wire:key="history-{{ $proposal->id }}">
                            <span class="font-semibold">{{ $proposal->key->label() }}</span>
                            {{ SettingValues::describe($proposal->key, $proposal->current_value) }} → {{ SettingValues::describe($proposal->key, $proposal->proposed_value) }}
                            <span class="block text-small text-ink-muted">
                                {{ ucfirst($proposal->status) }} by {{ '@'.($names[$proposal->decided_by] ?? 'unknown') }}
                                · proposed by {{ '@'.($names[$proposal->proposed_by] ?? 'unknown') }}
                                · {{ $proposal->decided_at?->setTimezone($zone)->format('j M, H:i') }}{{ $proposal->decision_note !== null ? ' · '.$proposal->decision_note : '' }}
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    </div>
</x-filament-panels::page>
