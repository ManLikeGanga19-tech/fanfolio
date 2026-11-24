{{-- Canvas FanMore · Top-up success (M3 PR 2), plus the waiting and declined states the board implies.
     "Back to the post" on the board returns to where the fan came from; until posts exist (M4) it is the wallet. --}}
@php
    use App\Modules\Wallet\Actions\WalletDisplayService;
    use App\Modules\Wallet\Enums\TopUpStatus;
    $row = 'flex items-center justify-between gap-4 border-b border-border py-3 last:border-b-0';
@endphp
<x-layouts.app title="Top-up">
    <div class="mx-auto flex max-w-md flex-col items-center gap-3.5 py-10 text-center">
        @if ($topUp->status === TopUpStatus::Paid)
            <span class="flex size-16 items-center justify-center rounded-full bg-success text-on-brand" aria-hidden="true"><x-ui.icon name="check" :size="28" :stroke="2.5" /></span>
            <h1 class="text-h2">{{ number_format($topUp->totalCredits()) }} credits added</h1>
            <p class="text-ink-muted">Your new balance is {{ number_format($balance) }} credits.</p>

            <dl class="w-full rounded-lg bg-surface px-4 text-left">
                <div class="{{ $row }}"><dt class="text-ink-muted">Amount</dt><dd class="font-semibold">{{ WalletDisplayService::dollars($topUp->amount_minor) }}</dd></div>
                @if ($topUp->bonus_credits > 0)
                    <div class="{{ $row }}"><dt class="text-ink-muted">Bonus</dt><dd class="font-semibold text-success">+{{ number_format($topUp->bonus_credits) }} credits</dd></div>
                @endif
                <div class="{{ $row }}"><dt class="text-ink-muted">Paid with</dt><dd class="font-semibold">{{ $topUp->payment_hint ?? ($topUp->method->value === 'crypto' ? 'Crypto (USDT)' : 'Card') }}</dd></div>
                <div class="{{ $row }}"><dt class="text-ink-muted">Reference</dt><dd class="font-semibold">{{ $topUp->reference() }}</dd></div>
                <div class="{{ $row }}"><dt class="text-ink-muted">On your statement</dt><dd class="font-semibold">{{ $descriptor }}</dd></div>
            </dl>

            <x-ui.button variant="brand" :href="route('wallet')">Back to your wallet</x-ui.button>
            <x-ui.button :href="route('wallet.topups.receipt', $topUp->id)">Download receipt</x-ui.button>
        @elseif ($topUp->status === TopUpStatus::Pending)
            <span class="flex size-16 items-center justify-center rounded-full bg-surface-raised text-ink" aria-hidden="true"><x-ui.icon name="clock" :size="28" /></span>
            <h1 class="text-h2">Waiting for your payment</h1>
            <p class="text-ink-muted">We add the credits as soon as our payment partner confirms it. This usually takes a few seconds.</p>
            <x-ui.button variant="brand" :href="route('wallet.topups.show', $topUp->id)">Check again</x-ui.button>
            @if ($topUp->checkout_url !== null)
                <x-ui.button :href="$topUp->checkout_url">Return to checkout</x-ui.button>
            @endif
        @else
            <span class="flex size-16 items-center justify-center rounded-full bg-surface-raised text-danger" aria-hidden="true"><x-ui.icon name="credit-card" :size="28" /></span>
            <h1 class="text-h2">The payment did not go through</h1>
            <p class="text-ink-muted">You were not charged and no credits were added. Try again or use another method.</p>
            <x-ui.button variant="brand" :href="route('wallet')">Try again</x-ui.button>
        @endif
    </div>
</x-layouts.app>
