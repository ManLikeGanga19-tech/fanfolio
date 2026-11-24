{{-- Screen: Log in, passkey first, email fallback (canvas boards AuthLogin, FanOnboarding · Log in).
     "Keep me logged in" on the board waits for a remember token that keeps the device limit and remote
     sign-out (SEC-05); see docs/screens/conformance.md. --}}
<x-layouts.auth title="Log in" :slide="\App\Support\View\AuthSlides::LOGIN" banner>
    <div class="flex flex-col gap-[18px]">
        <h1 class="font-display text-h1 font-bold lg:text-[40px] lg:leading-[48px]">Welcome back</h1>
        <p class="text-ink-muted">Log in with the passkey on this device. No password needed.</p>

        @if (session('status'))
            <p class="rounded-md bg-surface-raised p-4 text-small" role="status">{{ session('status') }}</p>
        @endif

        <div class="flex flex-col gap-2">
            <x-ui.button variant="brand" data-passkey="get"
                data-options-url="{{ route('login.passkey.options') }}"
                data-submit-url="{{ route('login.passkey') }}"><x-ui.icon name="passkey" :stroke="1.75" />Log in with passkey</x-ui.button>
            <p id="passkey-status" class="text-small text-danger" role="status" aria-live="polite"></p>
        </div>

        <x-ui.divider>or use email</x-ui.divider>

        <form method="POST" action="{{ route('login') }}" class="flex flex-col gap-[18px]" novalidate>
            @csrf
            <x-ui.input name="email" label="Email" type="email" autocomplete="username webauthn" placeholder="you@example.com" required />
            <x-ui.input name="password" label="Password" type="password" autocomplete="current-password" placeholder="Your password" required />
            <a class="self-end text-small font-semibold text-brand" href="{{ route('password.request') }}">Forgot password?</a>
            <x-ui.button type="submit">Log in</x-ui.button>
        </form>

        <p class="text-center text-ink-muted">
            New to {{ config('fanfolio.brand.name') }}? <a class="font-semibold text-brand" href="{{ route('signup') }}">Create an account</a>
        </p>
        <p class="mt-3 text-center text-small text-ink-muted">{{ config('fanfolio.brand.name') }} is for adults 18+. Protected by multi-factor sign-in.</p>
    </div>
</x-layouts.auth>
