{{-- Choose a new password (from the reset email). Not a canvas board; built in the log in screen's style. --}}
<x-layouts.app title="Choose a new password">
    <div class="mx-auto flex max-w-md flex-col gap-6 py-8">
        <div class="flex flex-col gap-2">
            <h1 class="text-h1">Choose a new password</h1>
            <p class="text-ink-muted">This signs you out on every device. If your account has a passkey or authenticator app, you still need it to log in.</p>
        </div>

        <form method="POST" action="{{ route('password.update') }}" class="flex flex-col gap-4" novalidate>
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">
            <x-ui.input name="email" label="Email" type="email" autocomplete="username" :value="$email" required />
            <x-ui.input name="password" label="New password" type="password" autocomplete="new-password" hint="At least 12 characters" minlength="12" required />
            <x-ui.input name="password_confirmation" label="Repeat new password" type="password" autocomplete="new-password" required />
            <x-ui.button variant="brand" type="submit">Change password</x-ui.button>
        </form>
    </div>
</x-layouts.app>
