{{-- Screen: Sign up, step 1 of 3 (account). --}}
<x-layouts.app title="Sign up">
    <div class="mx-auto flex max-w-md flex-col gap-6 py-8">
        <div class="flex flex-col gap-2">
            <p class="text-label text-ink-muted">STEP 1 OF 3 · ACCOUNT</p>
            <h1 class="text-h1">Create your account</h1>
            <p class="text-ink-muted">Takes under a minute. Creators only see your username.</p>
        </div>

        <form method="POST" action="{{ route('signup') }}" class="flex flex-col gap-4" novalidate>
            @csrf
            <x-ui.input name="email" label="Email" type="email" autocomplete="email" required />
            <x-ui.input name="username" label="Username" autocomplete="username" hint="Shown to creators instead of your name" required />
            <x-ui.input name="password" label="Password" type="password" autocomplete="new-password" hint="At least 12 characters" minlength="12" required />

            <div class="flex flex-col gap-1">
                <label class="flex items-start gap-2 text-small">
                    <input type="checkbox" name="adult_and_terms" value="1" class="mt-1 size-5 accent-brand" @checked(old('adult_and_terms'))
                        @error('adult_and_terms') aria-invalid="true" aria-describedby="adult-error" @enderror>
                    <span>
                        I am 18 or older and agree to the
                        <a class="text-brand underline" href="{{ route('legal.terms') }}">Terms</a> and
                        <a class="text-brand underline" href="{{ route('legal.privacy') }}">Privacy Policy</a>
                    </span>
                </label>
                @error('adult_and_terms')
                    <p id="adult-error" class="text-small text-danger" role="alert">{{ $message }}</p>
                @enderror
            </div>

            <x-ui.button variant="brand" type="submit">Continue</x-ui.button>
        </form>


        <p class="text-small text-ink-muted">
            Already on FanFolio? <a class="text-brand underline" href="{{ route('login') }}">Log in</a>
        </p>
    </div>
</x-layouts.app>
