{{-- Screen: Age verification (18+, optional), sign up step 3 (canvas board FanOnboarding · Age verification). --}}
<x-layouts.auth title="Confirm you are 18+">
    <div class="flex flex-col gap-5">
        <x-ui.progress-steps :total="3" :current="3" label="AGE CHECK" />
        <x-ui.illustration name="age-check" :size="100" />
        <div class="flex flex-col gap-2">
            <h1 class="text-h2">Confirm you are 18+</h1>
            <p class="text-ink-muted">
                Some creators share adult content. Verify once to see it. Our verification partner checks your ID;
                creators never see it and we never store the image.
            </p>
        </div>

        @error('method')
            <p class="rounded-md bg-surface-raised p-4 text-small text-danger" role="alert">{{ $message }}</p>
        @enderror

        @if ($handoff)
            {{-- Decision D-14: on a computer, the check continues on a phone. --}}
            <x-ui.phone-handoff :link="$handoff" :continue="route('signup.age.return', ['ref' => $pending->provider_ref])" :restart="route('signup.age.start')" :restart-fields="['method' => $pending->method->value]" :dev-mode="$devMode" />
        @else
        <form method="POST" action="{{ route('signup.age.start') }}" class="flex flex-col gap-4">
            @csrf
            <input type="hidden" name="device" value="computer" data-detect>
            <fieldset class="flex flex-col gap-2">
                <legend class="sr-only">How to verify</legend>

                <label class="flex cursor-pointer items-start gap-3 rounded-md border border-border bg-surface p-3.5 has-checked:border-2 has-checked:border-brand">
                    <input type="radio" name="method" value="document" class="mt-1 size-5 accent-brand" @checked(old('method', 'document') === 'document')>
                    <span class="flex flex-col gap-1">
                        <span class="font-semibold">Verify with ID <span class="ml-1 inline-flex h-[22px] items-center rounded-full bg-surface-raised px-2 text-[11px] leading-4 font-semibold text-success">Recommended</span></span>
                        <span class="text-small text-ink-muted">Passport, national ID or licence · about 2 min</span>
                    </span>
                </label>

                <label class="flex cursor-pointer items-start gap-3 rounded-md border border-border bg-surface p-3.5 has-checked:border-2 has-checked:border-brand">
                    <input type="radio" name="method" value="selfie_age" class="mt-1 size-5 accent-brand" @checked(old('method') === 'selfie_age')>
                    <span class="flex flex-col gap-1">
                        <span class="font-semibold">Selfie age check</span>
                        <span class="text-small text-ink-muted">No document needed · about 30 sec</span>
                    </span>
                </label>
            </fieldset>

            <x-ui.button variant="brand" type="submit">Verify my age</x-ui.button>
            <p class="text-small text-ink-muted">On a computer we'll show a QR code: the photo is taken with a phone camera.</p>
        </form>
        @endif

        <x-ui.button :href="route('account')">Not now, show safe content only</x-ui.button>
    </div>
</x-layouts.auth>
