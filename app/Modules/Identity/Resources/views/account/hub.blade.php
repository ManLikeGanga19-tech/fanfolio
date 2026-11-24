{{-- Screen: Account hub (canvas board FanMore · Account). The wallet card, Subscriptions, Purchases,
     Notifications, Privacy, Currency and Help rows join as their milestones land; profile photos with M4 (D-08). --}}
@php
    $row = 'flex min-h-[52px] items-center gap-3 border-b border-border pr-3 pl-4 last:border-b-0 hover:bg-surface-raised';
@endphp
<x-layouts.app title="Account">
    <div class="mx-auto flex max-w-md flex-col gap-4 py-8">
        <h1 class="text-h2">Account</h1>

        @if (session('status'))
            <p class="rounded-md bg-surface-raised p-4 text-small" role="status">{{ session('status') }}</p>
        @endif

        <div class="flex items-center gap-3.5">
            <span class="flex size-16 shrink-0 items-center justify-center rounded-full bg-border-strong font-display text-h3 font-bold text-ink" aria-hidden="true">{{ mb_strtoupper(mb_substr($user->username, 0, 1)) }}</span>
            <div class="flex flex-col items-start gap-1">
                <p class="text-h3">{{ '@'.$user->username }}</p>
                @if ($user->isAgeVerified())
                    <span class="inline-flex h-6 items-center rounded-full bg-surface-raised px-2.5 text-label text-success">Age verified</span>
                @endif
            </div>
        </div>

        <nav aria-label="Account" class="flex flex-col gap-4">
            <div class="overflow-hidden rounded-lg bg-surface">
                <a href="{{ route('account.security') }}" class="{{ $row }}">
                    <span class="flex-1">Devices &amp; security</span>
                    <span class="text-small text-ink-muted">{{ $deviceCount }} {{ $deviceCount === 1 ? 'device' : 'devices' }}</span>
                </a>
                @unless ($user->isAgeVerified())
                    <a href="{{ route('signup.age') }}" class="{{ $row }}">
                        <span class="flex-1">Verify your age to see adult creators</span>
                        <span class="text-small text-ink-muted">Adult: off</span>
                    </a>
                @endunless
                @if ($user->role->isStaff())
                    <a href="{{ url('/admin') }}" class="{{ $row }}"><span class="flex-1">Admin</span><span class="text-ink-muted" aria-hidden="true">›</span></a>
                @elseif ($user->role === \App\Modules\Identity\Enums\Role::Creator)
                    <a href="{{ url('/studio') }}" class="{{ $row }}"><span class="flex-1">Creator Studio</span><span class="text-ink-muted" aria-hidden="true">›</span></a>
                @else
                    <a href="{{ route('creator.start') }}" class="{{ $row }}"><span class="flex-1">Become a creator</span><span class="text-ink-muted" aria-hidden="true">›</span></a>
                @endif
            </div>

            <div class="overflow-hidden rounded-lg bg-surface">
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="{{ $row }} w-full text-left text-danger">Log out</button>
                </form>
            </div>
        </nav>
    </div>
</x-layouts.app>
