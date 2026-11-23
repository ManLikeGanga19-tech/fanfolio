{{-- Screen: Sign up, step 1 of 3 (account). --}}
<x-layouts.auth title="Sign up">
    <div class="flex flex-col gap-8">
        <div class="flex flex-col gap-2">
            <p class="text-label text-ink-muted">STEP 1 OF 3 · ACCOUNT</p>
            <h1 class="text-h1">Create your account</h1>
            <p class="text-ink-muted">Takes under a minute. Creators only see your username.</p>
        </div>

        <form id="signup-form" method="POST" action="{{ route('signup') }}" class="flex flex-col gap-4" novalidate>
            @csrf
            <x-ui.input name="email" label="Email" type="email" autocomplete="email" required />
            <x-ui.input name="username" label="Username" autocomplete="username" hint="Shown to creators instead of your name" required />
            {{-- Decision D-12: optional and unverified; the verified email is the primary contact. --}}
            <x-ui.input name="phone" label="Phone number (optional)" type="tel" autocomplete="tel" inputmode="tel" placeholder="+254" hint="With the country code. Kept private; we contact you by email." />
            <x-ui.input name="password" label="Password" type="password" autocomplete="new-password" hint="At least 12 characters" minlength="12" required />
            <x-ui.input name="password_confirmation" label="Confirm password" type="password" autocomplete="new-password" required />

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

        {{-- Uses the email, username and 18+ box above; no password needed. --}}
        <div class="flex flex-col gap-2">
            <p class="text-center text-small text-ink-muted">or</p>
            <x-ui.button data-passkey="create" data-form="signup-form"
                data-options-url="{{ route('signup.passkey.options') }}"
                data-submit-url="{{ route('signup.passkey') }}">Continue with a passkey</x-ui.button>
            <p id="passkey-status" class="text-small text-danger" role="status" aria-live="polite"></p>
        </div>


        <p class="text-small text-ink-muted">
            Already on FanFolio? <a class="text-brand underline" href="{{ route('login') }}">Log in</a>
        </p>
    </div>
</x-layouts.auth>
