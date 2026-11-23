{{-- Screen: sign up step 2 of 3. Laid out like the "Verify phone (OTP)" board, by email instead (decision D-12). --}}
<x-layouts.auth title="Verify your email">
    <div class="flex flex-col gap-8">
        <div class="flex flex-col gap-2">
            <p class="text-label text-ink-muted">STEP 2 OF 3 · EMAIL</p>
            <h1 class="text-h1">Verify your email</h1>
        </div>

        @if (session('status'))
            <p class="rounded-md bg-surface-raised p-4 text-small" role="status">{{ session('status') }}</p>
        @endif

        @if ($devMode)
            {{-- Decision D-14: local development only. --}}
            <p class="rounded-md border border-warning p-4 text-small" role="note">Development mode: any 6-digit code works.</p>
        @endif

        @if ($pending || $devMode)
            <p class="text-ink-muted">Enter the 6-digit code we sent to <span class="break-all text-ink">{{ $email }}</span>. Check your spam folder if it is not there.</p>

            <form method="POST" action="{{ route('signup.email.verify') }}" class="flex flex-col gap-4" novalidate>
                @csrf
                <x-ui.input name="code" label="Code" autocomplete="one-time-code" inputmode="numeric" maxlength="6" pattern="[0-9]{6}" class="tabular text-center text-h2 tracking-widest" required autofocus />
                <x-ui.button variant="brand" type="submit">Verify</x-ui.button>
            </form>
        @else
            <p class="text-ink-muted">We'll send a 6-digit code to <span class="break-all text-ink">{{ $email }}</span>.</p>
        @endif

        <div class="flex flex-col gap-2 text-small text-ink-muted">
            @if ($canResend)
                <form method="POST" action="{{ route('signup.email.send') }}">
                    @csrf
                    @if ($pending)
                        <button type="submit" class="inline-flex min-h-11 items-center text-brand underline">Resend code</button>
                    @else
                        <x-ui.button variant="brand" type="submit">Send code</x-ui.button>
                    @endif
                </form>
            @else
                <p>Resend code in <span class="tabular">{{ $resendIn }}</span></p>
            @endif
            @error('email')
                <p class="text-danger" role="alert">{{ $message }}</p>
            @enderror
        </div>
    </div>
</x-layouts.auth>
