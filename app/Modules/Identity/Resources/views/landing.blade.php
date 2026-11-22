{{-- Screen: Landing (logged out). Featured creators arrive with the Creators module (M2). --}}
<x-layouts.app>
    <x-slot:actions>
        <a href="{{ route('login') }}" class="rounded-md px-3 py-2 font-semibold text-ink hover:bg-surface-raised">Log in</a>
    </x-slot:actions>

    <section class="flex max-w-xl flex-col gap-6 py-8 md:py-16">
        <h1 class="text-h1 md:text-display">Support the creators you love, directly.</h1>
        <p class="text-ink-muted">
            Subscribe, unlock exclusive posts and message creators. Pay by card or crypto; creators get paid fast.
        </p>

        <div class="flex flex-col gap-2 sm:max-w-xs">
            <x-ui.button variant="brand" :href="route('signup')">Create free account</x-ui.button>
            <x-ui.button :href="route('signup')">I am a creator</x-ui.button>
        </div>

        <ul class="flex flex-col gap-2 text-small text-ink-muted">
            <li class="flex items-center gap-2"><span class="text-success" aria-hidden="true">✓</span> Every creator is ID-verified and 18+</li>
            <li class="flex items-center gap-2"><span class="text-success" aria-hidden="true">✓</span> Content is encrypted and watermarked</li>
            <li class="flex items-center gap-2"><span class="text-success" aria-hidden="true">✓</span> Discreet billing on your statement</li>
        </ul>
    </section>
</x-layouts.app>
