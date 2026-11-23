{{-- Recovery codes: shown once, straight after they are made. --}}
<x-layouts.app title="Recovery codes">
    <div class="mx-auto flex max-w-md flex-col gap-6 py-8">
        <div class="flex flex-col gap-2">
            <h1 class="text-h1">Save your recovery codes</h1>
            <p class="text-ink-muted">If you lose this phone, each code lets you log in once. Keep them somewhere safe. We will not show them again.</p>
        </div>

        <x-ui.card>
            <ul class="tabular grid grid-cols-2 gap-2 font-semibold tracking-wider" aria-label="Recovery codes">
                @foreach ($codes as $code)
                    <li>{{ $code }}</li>
                @endforeach
            </ul>
        </x-ui.card>

        <x-ui.button variant="brand" :href="route('account.security')">I have saved them</x-ui.button>
    </div>
</x-layouts.app>
