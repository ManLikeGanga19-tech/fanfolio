{{-- Decision D-03: a 4th device is refused; the user can sign out one of their devices and continue. --}}
<x-layouts.auth title="Too many devices">
    <div class="flex flex-col gap-8">
        <div class="flex flex-col gap-2">
            <h1 class="text-h1">You are signed in on {{ $limit }} devices</h1>
            <p class="text-ink-muted">Your subscriptions work on up to {{ $limit }} devices. Sign out of one to use this device.</p>
        </div>

        <ul class="flex flex-col divide-y divide-border rounded-lg bg-surface" aria-label="Active devices">
            @foreach ($devices as $device)
                <li class="flex items-center justify-between gap-4 p-4">
                    <div>
                        <p class="font-semibold">{{ $device->label }}</p>
                        <p class="text-small text-ink-muted">@if ($device->last_city){{ $device->last_city }} · @endif{{ $device->last_seen_at->diffForHumans() }}</p>
                    </div>
                    <form method="POST" action="{{ route('login.devices.destroy', $device->id) }}">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="min-h-11 px-2 text-small font-semibold text-danger">Sign out</button>
                    </form>
                </li>
            @endforeach
        </ul>

        <form method="POST" action="{{ route('login.devices.continue') }}">
            @csrf
            <x-ui.button variant="brand" type="submit" :disabled="! $canContinue">Continue on this device</x-ui.button>
        </form>
    </div>
</x-layouts.auth>
