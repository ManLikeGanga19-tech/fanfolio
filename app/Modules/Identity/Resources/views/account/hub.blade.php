{{-- Screen: Account hub. Wallet, subscriptions, purchases and preferences join as their milestones land. --}}
<x-layouts.app title="Account">
    <div class="mx-auto flex max-w-md flex-col gap-6 py-8">
        <h1 class="text-h1">Account</h1>

        @if (session('status'))
            <p class="rounded-md bg-surface-raised p-4 text-small" role="status">{{ session('status') }}</p>
        @endif

        <x-ui.card class="flex flex-col gap-1">
            <p class="text-h3">{{ '@'.$user->username }}</p>
            @if ($user->isAgeVerified())
                <p class="text-small text-success">✓ Age verified</p>
            @else
                <a href="{{ route('signup.age') }}" class="text-small text-brand underline">Verify your age to see adult creators</a>
            @endif
        </x-ui.card>

        <nav aria-label="Account" class="flex flex-col divide-y divide-border rounded-lg bg-surface">
            <a href="{{ route('account.security') }}" class="flex min-h-11 items-center justify-between px-4 py-3 hover:bg-surface-raised">
                <span>Devices &amp; security</span>
                <span class="text-small text-ink-muted">{{ $deviceCount }} {{ $deviceCount === 1 ? 'device' : 'devices' }}</span>
            </a>
            @if ($user->role->isStaff())
                <a href="{{ url('/admin') }}" class="flex min-h-11 items-center justify-between px-4 py-3 hover:bg-surface-raised">
                    <span>Admin</span>
                    <span class="text-small text-ink-muted" aria-hidden="true">›</span>
                </a>
            @elseif ($user->role === \App\Modules\Identity\Enums\Role::Creator)
                <a href="{{ url('/studio') }}" class="flex min-h-11 items-center justify-between px-4 py-3 hover:bg-surface-raised">
                    <span>Creator Studio</span>
                    <span class="text-small text-ink-muted" aria-hidden="true">›</span>
                </a>
            @else
                <a href="{{ route('creator.start') }}" class="flex min-h-11 items-center justify-between px-4 py-3 hover:bg-surface-raised">
                    <span>Become a creator</span>
                    <span class="text-small text-ink-muted" aria-hidden="true">›</span>
                </a>
            @endif
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="flex min-h-11 w-full items-center px-4 py-3 text-left text-danger hover:bg-surface-raised">Log out</button>
            </form>
        </nav>
    </div>
</x-layouts.app>
