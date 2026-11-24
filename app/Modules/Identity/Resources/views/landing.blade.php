{{-- Screen: Landing (logged out). Featured creators arrive with the Creators module (M2). --}}
<x-layouts.landing>
    <x-slot:actions>
        <a href="{{ route('login') }}" class="inline-flex min-h-11 items-center rounded-md px-4 font-semibold text-ink hover:bg-surface-raised">Log in</a>
    </x-slot:actions>

    <section class="flex max-w-xl flex-col gap-8">
        <div class="flex flex-col gap-4">
            <h1 class="text-h1 md:text-display">Support the creators you love, directly.</h1>
            <p class="text-ink-muted md:text-h3 md:font-normal">
                Subscribe, unlock exclusive posts and message creators. Pay by card or crypto; creators get paid fast.
            </p>
        </div>

        <div class="flex flex-col gap-3 sm:flex-row">
            <x-ui.button variant="brand" :href="route('signup')" class="sm:w-auto sm:px-8">Create free account</x-ui.button>
            <x-ui.button :href="route('signup')" class="sm:w-auto sm:px-8">I am a creator</x-ui.button>
        </div>

        <ul class="flex flex-col gap-2 text-small text-ink-muted">
            <li class="flex items-center gap-2"><span class="text-success" aria-hidden="true">✓</span> Every creator is ID-verified and 18+</li>
            <li class="flex items-center gap-2"><span class="text-success" aria-hidden="true">✓</span> Content is encrypted and watermarked</li>
            <li class="flex items-center gap-2"><span class="text-success" aria-hidden="true">✓</span> Discreet billing on your statement</li>
        </ul>
    </section>
</x-layouts.landing>
