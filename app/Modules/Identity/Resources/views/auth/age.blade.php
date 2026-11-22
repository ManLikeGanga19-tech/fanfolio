{{-- Screen: Age verification (18+, optional), sign up step 3. --}}
<x-layouts.app title="Confirm you are 18+">
    <div class="mx-auto flex max-w-md flex-col gap-6 py-8">
        <div class="flex flex-col gap-2">
            <p class="text-label text-ink-muted">STEP 3 OF 3 · AGE CHECK</p>
            <h1 class="text-h1">Confirm you are 18+</h1>
            <p class="text-ink-muted">
                Some creators share adult content. Verify once to see it. Our verification partner checks your ID;
                creators never see it and we never store the image.
            </p>
        </div>

        @error('method')
            <p class="rounded-md bg-surface-raised p-4 text-small text-danger" role="alert">{{ $message }}</p>
        @enderror

        <form method="POST" action="{{ route('signup.age.start') }}" class="flex flex-col gap-4">
            @csrf
            <fieldset class="flex flex-col gap-2">
                <legend class="sr-only">How to verify</legend>

                <label class="flex cursor-pointer items-start gap-4 rounded-lg border border-border-strong bg-surface p-4 has-checked:border-brand">
                    <input type="radio" name="method" value="document" class="mt-1 size-5 accent-brand" @checked(old('method', 'document') === 'document')>
                    <span class="flex flex-col gap-1">
                        <span class="font-semibold">Verify with ID <span class="ml-2 rounded-sm border border-brand px-1 text-label text-brand">Recommended</span></span>
                        <span class="text-small text-ink-muted">Passport, national ID or licence · about 2 min</span>
                    </span>
                </label>

                <label class="flex cursor-pointer items-start gap-4 rounded-lg border border-border-strong bg-surface p-4 has-checked:border-brand">
                    <input type="radio" name="method" value="selfie_age" class="mt-1 size-5 accent-brand" @checked(old('method') === 'selfie_age')>
                    <span class="flex flex-col gap-1">
                        <span class="font-semibold">Selfie age check</span>
                        <span class="text-small text-ink-muted">No document needed · about 30 sec</span>
                    </span>
                </label>
            </fieldset>

            <x-ui.button variant="brand" type="submit">Verify my age</x-ui.button>
        </form>

        <x-ui.button variant="ghost" :href="route('account')">Not now, show safe content only</x-ui.button>
    </div>
</x-layouts.app>
