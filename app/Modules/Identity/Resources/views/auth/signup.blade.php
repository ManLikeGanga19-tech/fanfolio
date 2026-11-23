{{-- Screen: Sign up, step 1 of 3 (canvas boards AuthSignup, FanOnboarding · Sign up).
     Kept by owner decisions: the optional phone (D-12, D-13), confirm password and show/hide.
     "I'm a creator" continues to Become a creator after the email step. --}}
@php
    $joiningAs = old('joining_as', request()->query('as') === 'creator' ? 'creator' : 'fan');
@endphp
<x-layouts.auth title="Sign up" :slide="\App\Support\View\AuthSlides::SIGNUP" banner>
    <div class="flex flex-col gap-[18px]">
        <x-ui.progress-steps :total="3" :current="1" label="ACCOUNT" class="lg:hidden" />
        <h1 class="font-display text-h1 font-bold lg:text-[40px] lg:leading-[48px]">Create your account</h1>
        <p class="text-ink-muted">Free, and it takes under a minute. Creators only see your username.</p>

        <form id="signup-form" method="POST" action="{{ route('signup') }}" class="flex flex-col gap-[18px]" novalidate>
            @csrf

            <fieldset class="grid grid-cols-2 gap-1 rounded-md bg-surface p-1">
                <legend class="sr-only">I am joining as</legend>
                @foreach (['fan' => "I'm a fan", 'creator' => "I'm a creator"] as $value => $label)
                    <label class="flex min-h-11 cursor-pointer items-center justify-center rounded-[8px] text-small text-ink-muted has-checked:bg-surface-raised has-checked:font-semibold has-checked:text-ink has-focus-visible:outline-2 has-focus-visible:outline-focus">
                        <input type="radio" name="joining_as" value="{{ $value }}" class="sr-only" @checked($joiningAs === $value)>{{ $label }}
                    </label>
                @endforeach
            </fieldset>

            <x-ui.input name="email" label="Email" type="email" autocomplete="email" placeholder="you@example.com" required />
            <x-ui.input name="username" label="Username" autocomplete="username" placeholder="@yourname" hint="Creators see this instead of your real name" required />
            {{-- Decision D-12: optional and unverified; the verified email is the primary contact. --}}
            <x-ui.input name="phone" label="Phone number (optional)" type="tel" autocomplete="tel" inputmode="tel" placeholder="+254" hint="With the country code. Kept private; we contact you by email." />
            <x-ui.input name="password" label="Password" type="password" autocomplete="new-password" placeholder="At least 12 characters" hint="At least 12 characters" minlength="12" required />
            <x-ui.input name="password_confirmation" label="Confirm password" type="password" autocomplete="new-password" required />

            <div class="flex flex-col gap-1">
                <label class="flex items-start gap-2.5 text-small text-ink-muted">
                    <input type="checkbox" name="adult_and_terms" value="1" class="mt-0.5 size-[18px] shrink-0 accent-brand" @checked(old('adult_and_terms'))
                        @error('adult_and_terms') aria-invalid="true" aria-describedby="adult-error" @enderror>
                    <span>
                        I am 18 or older and agree to the
                        <a class="text-brand" href="{{ route('legal.terms') }}">Terms</a> and
                        <a class="text-brand" href="{{ route('legal.privacy') }}">Privacy Policy</a>
                    </span>
                </label>
                @error('adult_and_terms')
                    <p id="adult-error" class="text-small text-danger" role="alert">{{ $message }}</p>
                @enderror
            </div>

            <x-ui.button variant="brand" type="submit">Continue</x-ui.button>
        </form>

        {{-- Uses the email, username and 18+ box above; no password needed. --}}
        <x-ui.divider>or</x-ui.divider>
        <div class="flex flex-col gap-2">
            <x-ui.button data-passkey="create" data-form="signup-form"
                data-options-url="{{ route('signup.passkey.options') }}"
                data-submit-url="{{ route('signup.passkey') }}"><x-ui.icon name="passkey" :stroke="1.75" />Sign up with a passkey</x-ui.button>
            <p id="passkey-status" class="text-small text-danger" role="status" aria-live="polite"></p>
        </div>

        <p class="text-center text-ink-muted">
            Already have an account? <a class="font-semibold text-brand" href="{{ route('login') }}">Log in</a>
        </p>
    </div>
</x-layouts.auth>
