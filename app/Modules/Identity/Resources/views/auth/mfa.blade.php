{{-- Second step of a password login for accounts with MFA. --}}
<x-layouts.auth title="Confirm it's you">
    <div class="flex flex-col gap-8">
        <div class="flex flex-col gap-2">
            <h1 class="text-h1">Confirm it's you</h1>
            <p class="text-ink-muted">Your account has a second sign-in method.</p>
        </div>

        @if ($hasPasskey)
            <div class="flex flex-col gap-2">
                <x-ui.button variant="brand" data-passkey="get"
                    data-options-url="{{ route('login.passkey.options') }}"
                    data-submit-url="{{ route('login.passkey') }}">Use your passkey</x-ui.button>
                <p id="passkey-status" class="text-small text-danger" role="status" aria-live="polite"></p>
            </div>
        @endif

        @if ($hasTotp)
            <form method="POST" action="{{ route('login.mfa') }}" class="flex flex-col gap-4" novalidate>
                @csrf
                <x-ui.input name="code" label="Code from your authenticator app" autocomplete="one-time-code" inputmode="numeric" maxlength="6" class="tabular" required :autofocus="! $hasPasskey" />
                <x-ui.button :variant="$hasPasskey ? 'secondary' : 'brand'" type="submit">Continue</x-ui.button>
            </form>
        @endif

        <details class="rounded-lg bg-surface p-4">
            <summary class="cursor-pointer font-semibold">Use a recovery code</summary>
            <form method="POST" action="{{ route('login.mfa') }}" class="mt-4 flex flex-col gap-4" novalidate>
                @csrf
                <x-ui.input name="recovery_code" label="Recovery code" autocomplete="off" hint="Each code works once." />
                <x-ui.button type="submit">Continue</x-ui.button>
            </form>
        </details>
    </div>
</x-layouts.auth>
