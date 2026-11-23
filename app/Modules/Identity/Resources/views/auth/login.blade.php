{{-- Screen: Log in (passkey first, email fallback). --}}
<x-layouts.app title="Log in">
    <div class="mx-auto flex max-w-md flex-col gap-6 py-8">
        <div class="flex flex-col gap-2">
            <h1 class="text-h1">Welcome back</h1>
            <p class="text-ink-muted">Log in with the passkey on this phone. No password needed.</p>
        </div>

        @if (session('status'))
            <p class="rounded-md bg-surface-raised p-4 text-small" role="status">{{ session('status') }}</p>
        @endif

        <div class="flex flex-col gap-2">
            <x-ui.button variant="brand" data-passkey="get"
                data-options-url="{{ route('login.passkey.options') }}"
                data-submit-url="{{ route('login.passkey') }}">Log in with passkey</x-ui.button>
            <p id="passkey-status" class="text-small text-danger" role="status" aria-live="polite"></p>
        </div>

        <p class="text-center text-small text-ink-muted">or use email</p>

        <form method="POST" action="{{ route('login') }}" class="flex flex-col gap-4" novalidate>
            @csrf
            <x-ui.input name="email" label="Email" type="email" autocomplete="username webauthn" required />
            <x-ui.input name="password" label="Password" type="password" autocomplete="current-password" required />
            <a class="self-end text-small text-brand underline" href="{{ route('password.request') }}">Forgot password?</a>
            <x-ui.button type="submit">Log in</x-ui.button>
        </form>

        <p class="text-small text-ink-muted">
            New to FanFolio? <a class="text-brand underline" href="{{ route('signup') }}">Create an account</a>
        </p>
    </div>
</x-layouts.app>
