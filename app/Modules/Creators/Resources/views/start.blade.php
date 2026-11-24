{{-- Screen: Creator onboarding 1 · Become a creator. --}}
<x-layouts.auth title="Become a creator">
    <div class="flex flex-col gap-8">
        <div class="flex flex-col gap-3">
            <h1 class="text-h1">Get paid by the fans who love your work</h1>
            <p class="text-ink-muted">Set your own prices, keep 80% of every sale and withdraw to your bank or crypto wallet.</p>
        </div>

        @if (session('status'))
            <p class="rounded-md bg-surface-raised p-4 text-small" role="status">{{ session('status') }}</p>
        @endif

        <ul class="flex flex-col gap-4">
            <li class="flex flex-col gap-1">
                <p class="font-semibold">Keep 80% of every sale</p>
                <p class="text-small text-ink-muted">Subscriptions, pay-per-view, tips and messages</p>
            </li>
            <li class="flex flex-col gap-1">
                <p class="font-semibold">Withdraw 7 days after each sale</p>
                <p class="text-small text-ink-muted">Bank, USDT or Paxum</p>
            </li>
            <li class="flex flex-col gap-1">
                <p class="font-semibold">Your content is protected</p>
                <p class="text-small text-ink-muted">Encrypted, watermarked and leak-tracked</p>
            </li>
        </ul>

        <x-ui.card class="flex flex-col gap-3">
            <p class="text-label text-ink-muted">WHAT YOU'LL NEED · ABOUT 5 MINUTES</p>
            <ul class="flex list-disc flex-col gap-2 pl-5 text-small">
                <li>A national ID, passport or driving licence</li>
                <li>A quick selfie video to prove it's you</li>
                <li>A bank account or crypto wallet in your name</li>
            </ul>
        </x-ui.card>

        <form method="POST" action="{{ route('creator.start') }}" class="flex flex-col gap-3">
            @csrf
            <x-ui.button variant="brand" type="submit">Start verification</x-ui.button>
            {{-- Decision D-01: ID images are never stored by us; the provider holds them. --}}
            <p class="text-small text-ink-muted">You must be 18 or older. Your ID is checked by our verification partner and stored encrypted.</p>
        </form>
    </div>
</x-layouts.auth>
