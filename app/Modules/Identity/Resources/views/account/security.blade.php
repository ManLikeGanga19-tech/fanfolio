{{-- Screen: Devices & security (canvas board FanMore · Devices & security). --}}
<x-layouts.app title="Devices & security">
    <div class="mx-auto flex max-w-md flex-col gap-6 py-8">
        <div class="flex items-center gap-3">
            <a href="{{ route('account') }}" class="-ml-2 flex size-11 items-center justify-center text-ink" aria-label="Back to account"><x-ui.icon name="arrow-left" :size="24" /></a>
            <h1 class="text-h2">Security</h1>
        </div>

        @if (session('status'))
            <p class="rounded-md bg-surface-raised p-4 text-small" role="status">{{ session('status') }}</p>
        @endif
        @if ($errors->any())
            <p class="rounded-md bg-surface-raised p-4 text-small text-danger" role="alert">{{ $errors->first() }}</p>
        @endif

        <section aria-labelledby="sign-in-heading" class="flex flex-col gap-2">
            <h2 id="sign-in-heading" class="text-label text-ink-muted">SIGN-IN</h2>

            <div class="flex flex-col divide-y divide-border rounded-lg bg-surface">
                @forelse ($passkeys as $passkey)
                    <div class="flex items-center gap-3 px-4 py-3.5">
                        <x-ui.icon-circle name="key" tone="text-success" />
                        <div class="flex-1">
                            <p class="font-semibold">Passkey · {{ $passkey->alias ?? 'This device' }}</p>
                            <p class="text-small text-ink-muted">
                                Added {{ $passkey->created_at?->format('j M') }}@if ($passkey->last_used_at) · used {{ \Illuminate\Support\Carbon::parse($passkey->last_used_at)->diffForHumans() }}@endif
                            </p>
                        </div>
                        <form method="POST" action="{{ route('account.passkeys.destroy', $passkey->id) }}">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="min-h-11 px-2 text-small text-danger">Remove</button>
                        </form>
                        <span class="inline-flex h-6 items-center rounded-full bg-surface-raised px-2.5 text-label text-success">On</span>
                    </div>
                @empty
                    <div class="flex items-center gap-3 px-4 py-3.5">
                        <x-ui.icon-circle name="key" tone="text-ink-muted" />
                        <div class="flex-1">
                            <p class="font-semibold">Passkey</p>
                            <p class="text-small text-ink-muted">Log in with your fingerprint, face or screen lock.</p>
                        </div>
                        <span class="text-small text-ink-muted">Off</span>
                    </div>
                @endforelse

                <div class="flex flex-col gap-2 p-4">
                    <x-ui.button :variant="$passkeys->isEmpty() ? 'brand' : 'secondary'" data-passkey="create"
                        data-options-url="{{ route('account.passkeys.options') }}"
                        data-submit-url="{{ route('account.passkeys.store') }}">Add a passkey on this device</x-ui.button>
                    <p id="passkey-status" class="text-small text-danger" role="status" aria-live="polite"></p>
                </div>

                <div class="flex items-center gap-3 px-4 py-3.5">
                    <x-ui.icon-circle name="smartphone" :tone="$hasTotp ? 'text-success' : 'text-ink-muted'" />
                    <div class="flex-1">
                        <p class="font-semibold">Authenticator app</p>
                        <p class="text-small text-ink-muted">Backup sign-in method</p>
                    </div>
                    @if ($hasTotp)
                        <form method="POST" action="{{ route('account.totp.destroy') }}">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="min-h-11 px-2 text-small text-danger">Remove</button>
                        </form>
                    @else
                        <form method="POST" action="{{ route('account.totp.start') }}">
                            @csrf
                            <button type="submit" class="min-h-11 px-2 font-semibold text-brand">Set up</button>
                        </form>
                    @endif
                </div>

                @if ($user->mfa_enabled)
                    <div class="flex items-center justify-between gap-4 p-4">
                        <div>
                            <p class="font-semibold">Recovery codes</p>
                            <p class="text-small text-ink-muted">{{ $recoveryCodesLeft }} left</p>
                        </div>
                        <form method="POST" action="{{ route('account.security.recovery-codes.regenerate') }}">
                            @csrf
                            <button type="submit" class="min-h-11 px-2 text-small font-semibold text-ink">Get new codes</button>
                        </form>
                    </div>
                @endif
            </div>
        </section>

        <section aria-labelledby="devices-heading" class="flex flex-col gap-2">
            <h2 id="devices-heading" class="text-label text-ink-muted">ACTIVE DEVICES <span class="tabular">{{ $devices->count() }} of {{ $deviceLimit }}</span> allowed</h2>

            <ul class="flex flex-col divide-y divide-border rounded-lg bg-surface">
                @foreach ($devices as $device)
                    <li class="flex items-center justify-between gap-4 p-4">
                        <div>
                            <p class="font-semibold">{{ $device->label }}</p>
                            <p class="text-small text-ink-muted">
                                @if ($device->last_city){{ $device->last_city }} · @endif
                                @if ($device->id === $currentDeviceId) active now @else {{ $device->last_seen_at->diffForHumans() }} @endif
                            </p>
                        </div>
                        @if ($device->id === $currentDeviceId)
                            <span class="inline-flex h-6 items-center rounded-full bg-surface-raised px-2.5 text-label">This device</span>
                        @else
                            <form method="POST" action="{{ route('account.security.devices.destroy', $device->id) }}">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="min-h-11 px-2 text-small font-semibold text-danger">Sign out</button>
                            </form>
                        @endif
                    </li>
                @endforeach
            </ul>

            @if ($devices->count() > 1)
                <form method="POST" action="{{ route('account.security.devices.others') }}">
                    @csrf
                    <x-ui.button type="submit">Sign out of all other devices</x-ui.button>
                </form>
            @endif

            <p class="text-small text-ink-muted">
                Your subscriptions work on up to {{ $deviceLimit }} devices. Everything you watch is watermarked to your account on every device.
            </p>
        </section>

    </div>
</x-layouts.app>
