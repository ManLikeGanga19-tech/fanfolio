{{-- Screen: Verify phone (OTP), step 2 of 3. --}}
<x-layouts.auth title="Verify your phone">
    <div class="flex flex-col gap-8">
        <div class="flex flex-col gap-2">
            <p class="text-label text-ink-muted">STEP 2 OF 3 · PHONE</p>
            <h1 class="text-h1">Verify your phone</h1>
        </div>

        @if ($maskedPhone === null)
            <form method="POST" action="{{ route('signup.phone.send') }}" class="flex flex-col gap-4" novalidate>
                @csrf
                <x-ui.input name="phone" label="Phone number" type="tel" autocomplete="tel" inputmode="tel" placeholder="+254" hint="We send a 6-digit code by SMS." required />
                <x-ui.button variant="brand" type="submit">Send code</x-ui.button>
            </form>
        @else
            <p class="text-ink-muted">Enter the 6-digit code we sent to <span class="tabular text-ink">{{ $maskedPhone }}</span>.</p>

            <form method="POST" action="{{ route('signup.phone.verify') }}" class="flex flex-col gap-4" novalidate>
                @csrf
                <x-ui.input name="code" label="Code" autocomplete="one-time-code" inputmode="numeric" maxlength="6" pattern="[0-9]{6}" class="tabular text-center text-h2 tracking-widest" required autofocus />
                <x-ui.button variant="brand" type="submit">Verify</x-ui.button>
            </form>

            <div class="flex flex-col gap-2 text-small text-ink-muted">
                @if ($canResend)
                    <form method="POST" action="{{ route('signup.phone.send') }}">
                        @csrf
                        <input type="hidden" name="resend" value="1">
                        <button type="submit" class="text-brand underline">Resend code</button>
                    </form>
                @else
                    <p>Resend code in <span class="tabular">{{ $resendIn }}</span></p>
                @endif
                @error('phone')
                    <p class="text-danger" role="alert">{{ $message }}</p>
                @enderror
                <p>Wrong number? <a class="text-brand underline" href="{{ route('signup.phone', ['change' => 1]) }}">Change it</a></p>
            </div>
        @endif
    </div>
</x-layouts.auth>
