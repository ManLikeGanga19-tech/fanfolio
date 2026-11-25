{{-- Canvas StudioEarnings (M3 PR 4). "Paid to collaborators" arrives with collaboration splits (M7). --}}
@php
    use App\Modules\Wallet\Enums\PayoutState;
    use App\Modules\Wallet\Filament\Studio\EarningsPage;
    $zone = \Filament\Support\Facades\FilamentTimezone::get();
    $tones = [
        'Sent' => 'text-success', 'Under review' => 'text-warning', 'Sending' => 'text-ink',
        'Failed · refunded' => 'text-danger', 'Declined · refunded' => 'text-danger',
    ];
    $rails = ['bank' => 'Bank account', 'usdt' => 'USDT wallet', 'paxum' => 'Paxum'];
    $cell = 'whitespace-nowrap border-b border-border px-3 py-3';
@endphp
<x-filament-panels::page>
    <div class="flex flex-col gap-6">
        <div class="grid grid-cols-[repeat(auto-fit,minmax(200px,1fr))] gap-4">
            <div class="flex flex-col gap-1 rounded-lg border-2 border-brand bg-surface p-5">
                <span class="text-small text-ink-muted">Available to withdraw</span>
                <span class="font-display text-h1 text-brand">{{ EarningsPage::money($available) }}</span>
                <span class="text-small text-ink-muted">{{ $available > 0 ? 'Ready now' : 'Nothing to withdraw yet' }}</span>
            </div>
            <div class="flex flex-col gap-1 rounded-lg border border-border bg-surface p-5">
                <span class="text-small text-ink-muted">Pending · releases after {{ config('fanfolio.money.earnings_hold_days') }} days</span>
                <span class="font-display text-h1">{{ EarningsPage::money($pending) }}</span>
                <span class="text-small text-ink-muted">{{ $nextRelease !== null ? 'Next release '.$nextRelease->setTimezone($zone)->format('j M') : 'Nothing pending' }}</span>
            </div>
            <div class="flex flex-col gap-1 rounded-lg border border-border bg-surface p-5">
                <span class="text-small text-ink-muted">Paid out all time</span>
                <span class="font-display text-h1">{{ EarningsPage::money($paidOut) }}</span>
                <span class="text-small text-ink-muted">{{ $firstSent !== null ? 'Since '.$firstSent->setTimezone($zone)->format('F Y') : 'No payouts yet' }}</span>
            </div>
        </div>

        <div class="flex flex-wrap items-start gap-4">
            <section aria-labelledby="withdraw-title" class="flex min-w-0 flex-[1_1_320px] flex-col gap-3.5 rounded-lg border border-border bg-surface p-6">
                <h2 id="withdraw-title" class="font-display text-h3">Withdraw</h2>
                @if ($destinations === [])
                    <p class="text-small text-ink-muted">Add a payout method to withdraw.</p>
                @else
                    <form wire:submit="requestWithdrawal" class="flex flex-col gap-3.5">
                        <div class="flex flex-col gap-2">
                            <label for="payout-amount" class="font-semibold">Amount</label>
                            <div class="flex h-12 items-center gap-2 rounded-md border border-border-strong bg-surface-raised px-3.5">
                                <span class="text-ink-muted" aria-hidden="true">$</span>
                                <input id="payout-amount" type="text" inputmode="decimal" wire:model="amount" placeholder="0.00" autocomplete="off"
                                    class="h-full min-w-0 flex-1 border-0 bg-transparent p-0 text-ink focus:ring-0"
                                    aria-describedby="payout-amount-help @error('amount') payout-amount-error @enderror" @error('amount') aria-invalid="true" @enderror>
                                <button type="button" wire:click="fillMax" class="font-semibold text-brand">Max</button>
                            </div>
                            <p id="payout-amount-help" class="text-small text-ink-muted">Minimum {{ EarningsPage::money($minimum) }}.</p>
                            @error('amount')<p id="payout-amount-error" class="text-small text-danger" role="alert">{{ $message }}</p>@enderror
                        </div>

                        <fieldset class="flex flex-col gap-2">
                            <legend class="mb-2 font-semibold">Send to</legend>
                            @foreach ($destinations as $destination)
                                <label wire:key="dest-{{ $destination->id }}" class="flex min-h-14 cursor-pointer items-center gap-3 rounded-md border border-border bg-bg px-3.5 py-2 has-checked:border-brand">
                                    <input type="radio" wire:model="method" value="{{ $destination->id }}" class="size-4 accent-brand">
                                    <span><strong class="block">{{ $rails[$destination->rail->value] }}</strong><span class="text-small text-ink-muted">{{ $destination->displayHint }}</span></span>
                                </label>
                            @endforeach
                            @error('method')<p class="text-small text-danger" role="alert">{{ $message }}</p>@enderror
                        </fieldset>

                        <x-filament::button type="submit" size="lg">Request withdrawal</x-filament::button>
                        <p class="text-small text-ink-muted">You'll confirm with your passkey or authenticator app. Requests over {{ EarningsPage::money($reviewAbove) }} are reviewed within 24 hours.</p>
                    </form>
                @endif
            </section>

            <section aria-labelledby="history-title" class="min-w-0 flex-[2_1_460px] overflow-x-auto rounded-lg border border-border bg-surface p-6">
                <h2 id="history-title" class="mb-2 font-display text-h3">Withdrawal history</h2>
                @if ($history->isEmpty())
                    <p class="text-small text-ink-muted">No withdrawals yet.</p>
                @else
                    <table class="w-full border-collapse text-sm">
                        <thead>
                            <tr class="text-left text-label uppercase tracking-wide text-ink-muted">
                                <th scope="col" class="{{ $cell }}">Date</th>
                                <th scope="col" class="{{ $cell }}">Method</th>
                                <th scope="col" class="{{ $cell }}">Amount</th>
                                <th scope="col" class="{{ $cell }}">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($history as $payout)
                                <tr wire:key="payout-{{ $payout->id }}">
                                    <td class="{{ $cell }}">{{ $payout->requested_at->setTimezone($zone)->format('j M') }}</td>
                                    <td class="{{ $cell }}">{{ $rails[$payout->rail->value] }} · {{ $payout->display_hint }}</td>
                                    <td class="{{ $cell }}">{{ EarningsPage::money($payout->amount_minor) }}</td>
                                    <td class="{{ $cell }}"><span class="inline-flex h-6 items-center rounded-pill bg-surface-raised px-2.5 text-label {{ $tones[$payout->status->label()] ?? 'text-ink' }}">{{ $payout->status->label() }}</span></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </section>
        </div>
    </div>
</x-filament-panels::page>
