{{-- Forgot password: not a canvas board; built in the log in screen's style. --}}
<x-layouts.auth title="Reset your password">
    <div class="flex flex-col gap-8">
        <div class="flex flex-col gap-2">
            <h1 class="text-h1">Reset your password</h1>
            <p class="text-ink-muted">Enter the email on your account. We will send you a link to choose a new password.</p>
        </div>

        @if (session('status'))
            <p class="rounded-md bg-surface-raised p-4 text-small" role="status">{{ session('status') }}</p>
        @endif

        <form method="POST" action="{{ route('password.email') }}" class="flex flex-col gap-4" novalidate>
            @csrf
            <x-ui.input name="email" label="Email" type="email" autocomplete="email" required />
            <x-ui.button variant="brand" type="submit">Send reset link</x-ui.button>
        </form>

        <p class="text-small text-ink-muted">
            Remembered it? <a class="text-brand underline" href="{{ route('login') }}">Log in</a>
        </p>
    </div>
</x-layouts.auth>
