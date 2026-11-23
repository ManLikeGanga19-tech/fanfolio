{{-- Screen: Creator onboarding 5 · Payout method (step 4 of 4). Needs a recent step-up (SEC-04).
     Without JavaScript every rail's fields show; with :has() support only the chosen rail's do. --}}
@php
    $chosen = old('rail', $current?->rail->value ?? 'bank');
    $rails = [
        'bank' => ['Bank account', 'Kenyan banks · 1–2 business days'],
        'usdt' => ['USDT wallet', 'TRC-20 · within 1 hour'],
        'paxum' => ['Paxum', 'Within 1 business day'],
    ];
    $railError = $errors->first('rail');
@endphp
<x-layouts.auth title="Payout method">
    <div class="flex flex-col gap-8">
        <div class="flex flex-col gap-2">
            <p class="text-label text-ink-muted">STEP 4 OF 4 · GET PAID</p>
            <h1 class="text-h1">Where should we send your money?</h1>
        </div>

        @foreach (['kyc', 'payout'] as $field)
            @error($field)
                <p class="rounded-md border border-danger p-4 text-small text-danger" role="alert">{{ $message }}</p>
            @enderror
        @endforeach

        @if ($current)
            <x-ui.card class="flex flex-col gap-3">
                <p class="text-small text-ink-muted">Payouts go to</p>
                <p class="font-semibold">{{ $current->display_hint }}</p>
                <a href="{{ route('creator.review') }}" class="inline-flex min-h-11 items-center font-semibold text-brand underline">Keep this and continue</a>
            </x-ui.card>
            <h2 class="text-h3">Change payout method</h2>
        @endif

        <form method="POST" action="{{ route('creator.payout') }}" class="flex flex-col gap-4" novalidate>
            @csrf

            <fieldset class="flex flex-col gap-3" @if ($railError) aria-describedby="rail-error" @endif>
                <legend class="sr-only">Payout method</legend>
                @if ($railError)
                    <p id="rail-error" class="text-small text-danger" role="alert">{{ $railError }}</p>
                @endif

                @foreach ($rails as $value => [$name, $timing])
                    <div class="group flex flex-col gap-4 rounded-md border border-border-strong bg-surface p-4 has-checked:border-brand">
                        <label class="flex min-h-11 items-start gap-3">
                            <input type="radio" name="rail" value="{{ $value }}" class="mt-1 size-5 accent-brand" @checked($chosen === $value)>
                            <span class="flex flex-col gap-1">
                                <span class="font-semibold">{{ $name }}</span>
                                <span class="text-small text-ink-muted">{{ $timing }}</span>
                            </span>
                        </label>

                        <div class="flex flex-col gap-4 hidden group-has-checked:flex">
                            @if ($value === 'bank')
                                <x-ui.input name="bank_name" label="Bank" autocomplete="off" maxlength="60" />
                                <x-ui.input name="account_number" label="Account number" inputmode="numeric" autocomplete="off" maxlength="30" />
                            @elseif ($value === 'usdt')
                                <x-ui.input name="wallet_address" label="TRC-20 wallet address" autocomplete="off" autocapitalize="none" spellcheck="false" maxlength="34" />
                            @else
                                <x-ui.input name="paxum_email" label="Paxum email" type="email" autocomplete="off" maxlength="254" />
                            @endif
                        </div>
                    </div>
                @endforeach
            </fieldset>

            <x-ui.input name="account_name" label="Name on the account" autocomplete="off" maxlength="100"
                :value="$legalName"
                :hint="$legalName ? 'It must match your ID: '.$legalName.'. The account has to be in your name.' : 'The account has to be in your name.'" />

            <x-ui.button variant="brand" type="submit">Review and submit</x-ui.button>
        </form>
    </div>
</x-layouts.auth>
