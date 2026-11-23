{{-- Screen: Devices & security. Sign-in section; active devices join in the next PR. --}}
<x-layouts.app title="Devices & security">
    <div class="mx-auto flex max-w-md flex-col gap-6 py-8">
        <h1 class="text-h1">Security</h1>

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
                    <div class="flex items-center justify-between gap-4 p-4">
                        <div>
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
                    </div>
                @empty
                    <div class="flex items-center justify-between gap-4 p-4">
                        <div>
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

                <div class="flex items-center justify-between gap-4 p-4">
                    <div>
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
                            <span class="text-small text-success">This device</span>
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

        <a href="{{ route('account') }}" class="text-small text-ink-muted underline">Back to account</a>
    </div>
</x-layouts.app>
