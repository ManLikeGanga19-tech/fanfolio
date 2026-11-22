{{-- Step-up: confirm a factor before a sensitive change. --}}
<x-layouts.app title="Confirm it's you">
    <div class="mx-auto flex max-w-md flex-col gap-6 py-8">
        <div class="flex flex-col gap-2">
            <h1 class="text-h1">Confirm it's you</h1>
            <p class="text-ink-muted">This change needs a quick check.</p>
        </div>

        @if ($hasPasskey)
            <div class="flex flex-col gap-2">
                <x-ui.button variant="brand" data-passkey="get"
                    data-options-url="{{ route('account.confirm.passkey.options') }}"
                    data-submit-url="{{ route('account.confirm.passkey') }}">Use your passkey</x-ui.button>
                <p id="passkey-status" class="text-small text-danger" role="status" aria-live="polite"></p>
            </div>
        @endif

        <form method="POST" action="{{ route('account.confirm') }}" class="flex flex-col gap-4" novalidate>
            @csrf
            @if ($usesPassword)
                <x-ui.input name="password" label="Password" type="password" autocomplete="current-password" required />
            @elseif ($hasTotp)
                <x-ui.input name="code" label="Code from your authenticator app" autocomplete="one-time-code" inputmode="numeric" maxlength="6" class="tabular" />
            @endif
            @if ($usesPassword || $hasTotp)
                <x-ui.button :variant="$hasPasskey ? 'secondary' : 'brand'" type="submit">Confirm</x-ui.button>
            @endif
        </form>

        @unless ($usesPassword)
            <details class="rounded-lg bg-surface p-4">
                <summary class="cursor-pointer font-semibold">Use a recovery code</summary>
                <form method="POST" action="{{ route('account.confirm') }}" class="mt-4 flex flex-col gap-4" novalidate>
                    @csrf
                    <x-ui.input name="recovery_code" label="Recovery code" autocomplete="off" />
                    <x-ui.button type="submit">Confirm</x-ui.button>
                </form>
            </details>
        @endunless
    </div>
</x-layouts.app>
