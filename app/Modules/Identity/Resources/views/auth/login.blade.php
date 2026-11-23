{{-- Screen: Log in (passkey first, email fallback). --}}
<x-layouts.app title="Log in">
    <div class="mx-auto flex max-w-md flex-col gap-6 py-8">
        <h1 class="text-h1">Welcome back</h1>


        <form method="POST" action="{{ route('login') }}" class="flex flex-col gap-4" novalidate>
            @csrf
            <x-ui.input name="email" label="Email" type="email" autocomplete="username webauthn" required />
            <x-ui.input name="password" label="Password" type="password" autocomplete="current-password" required />
            <x-ui.button variant="brand" type="submit">Log in</x-ui.button>
        </form>

        <p class="text-small text-ink-muted">
            New to FanFolio? <a class="text-brand underline" href="{{ route('signup') }}">Create an account</a>
        </p>
    </div>
</x-layouts.app>
