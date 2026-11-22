{{-- Screen: Account hub. Wallet, subscriptions, purchases and preferences join as their milestones land. --}}
<x-layouts.app title="Account">
    <div class="mx-auto flex max-w-md flex-col gap-6 py-8">
        <h1 class="text-h1">Account</h1>

        <x-ui.card class="flex flex-col gap-1">
            <p class="text-h3">{{ '@'.$user->username }}</p>
            @if ($user->isAgeVerified())
                <p class="text-small text-success">✓ Age verified</p>
            @endif
        </x-ui.card>

        <nav aria-label="Account" class="flex flex-col divide-y divide-border rounded-lg bg-surface">
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="flex min-h-11 w-full items-center px-4 py-3 text-left text-danger hover:bg-surface-raised">Log out</button>
            </form>
        </nav>
    </div>
</x-layouts.app>
