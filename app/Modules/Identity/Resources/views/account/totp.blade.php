{{-- Set up an authenticator app. On a phone, the link opens the app directly; elsewhere, type the key. --}}
<x-layouts.app title="Set up authenticator app">
    <div class="mx-auto flex max-w-md flex-col gap-6 py-8">
        <div class="flex flex-col gap-2">
            <h1 class="text-h1">Set up authenticator app</h1>
            <p class="text-ink-muted">Add FanFolio to an authenticator app, then enter the 6-digit code it shows.</p>
        </div>

        <x-ui.card class="flex flex-col gap-4">
            <a href="{{ $uri }}" class="font-semibold text-brand underline">Open in authenticator app</a>
            <div class="flex flex-col gap-1">
                <p class="text-label text-ink-muted">OR ENTER THIS KEY</p>
                <p class="tabular break-all font-semibold tracking-wider" aria-label="Set-up key">{{ $secret }}</p>
            </div>
        </x-ui.card>

        <form method="POST" action="{{ route('account.totp.confirm') }}" class="flex flex-col gap-4" novalidate>
            @csrf
            <x-ui.input name="code" label="6-digit code" autocomplete="one-time-code" inputmode="numeric" maxlength="6" class="tabular" required />
            <x-ui.button variant="brand" type="submit">Turn on</x-ui.button>
        </form>
    </div>
</x-layouts.app>
