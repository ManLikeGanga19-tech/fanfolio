{{-- Screen: Creator onboarding 1 · Become a creator (canvas board Main · 1). --}}
<x-layouts.auth title="Become a creator">
    <div class="flex flex-col gap-6">
        <a href="{{ route('account') }}" class="-ml-2 flex size-11 items-center justify-center text-ink" aria-label="Back to your account"><x-ui.icon name="arrow-left" :size="24" /></a>

        <div class="flex flex-col gap-3">
            <h1 class="text-h1">Get paid by the fans who love your work</h1>
            <p class="text-ink-muted">Set your own prices, keep 80% of every sale and withdraw to your bank or crypto wallet.</p>
        </div>

        @if (session('status'))
            <p class="rounded-md bg-surface-raised p-4 text-small" role="status">{{ session('status') }}</p>
        @endif

        <x-ui.card>
            <ul class="flex flex-col gap-3.5">
                @foreach ([
                    ['wallet', 'Keep 80% of every sale', 'Subscriptions, pay-per-view, tips and messages'],
                    ['clock', 'Withdraw 7 days after each sale', 'Bank, USDT or Paxum'],
                    ['lock', 'Your content is protected', 'Encrypted, watermarked and leak-tracked'],
                ] as [$icon, $title, $detail])
                    <li class="flex items-center gap-3">
                        <x-ui.icon-circle :name="$icon" />
                        <span class="flex flex-col">
                            <span class="font-semibold">{{ $title }}</span>
                            <span class="text-small text-ink-muted">{{ $detail }}</span>
                        </span>
                    </li>
                @endforeach
            </ul>
        </x-ui.card>

        <div class="flex flex-col gap-3">
            <h2 class="text-label text-ink-muted">WHAT YOU'LL NEED · ABOUT 5 MINUTES</h2>
            <ul class="flex flex-col gap-2.5">
                @foreach (['A national ID, passport or driving licence', "A quick selfie video to prove it's you", 'A bank account or crypto wallet in your name'] as $need)
                    <li class="flex items-center gap-3"><x-ui.check-badge />{{ $need }}</li>
                @endforeach
            </ul>
        </div>

        <form method="POST" action="{{ route('creator.start') }}" class="flex flex-col gap-3">
            @csrf
            <x-ui.button variant="brand" type="submit">Start verification</x-ui.button>
            {{-- Decision D-01: ID images are never stored by us; the provider holds them. --}}
            <p class="text-center text-small text-ink-muted">You must be 18 or older. Your ID is checked by our verification partner. We never store the image.</p>
        </form>
    </div>
</x-layouts.auth>
