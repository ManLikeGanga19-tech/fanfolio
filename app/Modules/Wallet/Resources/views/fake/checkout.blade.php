{{-- Local and testing only: stands in for the payment processor's hosted checkout. --}}
<x-layouts.app title="Test checkout">
    <div class="mx-auto flex max-w-md flex-col gap-4 py-10">
        <p class="rounded-md bg-surface-raised p-3 text-small text-warning">Test checkout. No real payment is taken.</p>
        <h1 class="text-h2">Pay {{ \App\Modules\Wallet\Actions\WalletDisplayService::dollars($topUp->amount_minor) }}</h1>
        <p class="text-ink-muted">{{ number_format($topUp->totalCredits()) }} credits · {{ $topUp->method->value === 'crypto' ? 'Crypto (USDT)' : 'Card' }}</p>
        <form method="POST" action="{{ route('fake.checkout', $topUp->gateway_ref) }}" class="flex flex-col gap-3">
            @csrf
            <x-ui.button type="submit" variant="brand" name="outcome" value="pay">Pay</x-ui.button>
            <x-ui.button type="submit" name="outcome" value="decline">Decline</x-ui.button>
        </form>
    </div>
</x-layouts.app>
