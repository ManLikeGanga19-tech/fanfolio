{{-- Canvas FanCore · Wallet & top-up (M3 PR 2). The bottom tab bar joins with the feed (M5). Packs and
     methods are real radio buttons styled as the board's cards, so the form works without JavaScript. --}}
@php
    $card = 'relative flex min-h-[84px] cursor-pointer flex-col items-center justify-center gap-0.5 rounded-md border border-border bg-surface px-3 text-center font-semibold has-checked:border-2 has-checked:border-brand has-focus-visible:outline-2 has-focus-visible:outline-offset-2 has-focus-visible:outline-focus';
    $method = 'flex min-h-11 flex-1 cursor-pointer items-center justify-center rounded-md border border-border-strong bg-surface px-4 font-semibold has-checked:border-2 has-checked:border-brand has-focus-visible:outline-2 has-focus-visible:outline-offset-2 has-focus-visible:outline-focus';
    $chosenPack = (int) old('pack', 1);
    $chosenMethod = old('method', 'card');
@endphp
<x-layouts.app title="Wallet">
    <div class="mx-auto flex max-w-md flex-col gap-5 py-8">
        <h1 class="text-h2">Wallet</h1>

        <section aria-labelledby="balance-label" class="rounded-lg border border-border bg-surface p-5">
            <p id="balance-label" class="text-small text-ink-muted">Available credits</p>
            <p class="font-display text-[48px] leading-[52px] font-bold">{{ number_format($credits) }} <span class="font-sans text-base font-normal text-ink-muted">credits</span></p>
            @if ($approximate !== null)
                <p class="text-small text-ink-muted">{{ $approximate }}</p>
            @endif
        </section>

        <form method="POST" action="{{ route('wallet.topups.store') }}" class="flex flex-col gap-5">
            @csrf
            <input type="hidden" name="idempotency_key" value="{{ $idempotencyKey }}">

            <fieldset class="flex flex-col gap-3">
                <legend class="mb-3 text-h3">Top up</legend>
                <div class="grid grid-cols-2 gap-2">
                    @foreach ($packs as $pack)
                        <label class="{{ $card }}">
                            <input type="radio" name="pack" value="{{ $pack->index }}" class="sr-only" @checked($chosenPack === $pack->index) required>
                            <span>{{ number_format($pack->totalCredits()) }} credits</span>
                            <span class="text-small font-normal text-ink-muted">{{ $pack->priceLabel() }}@if ($pack->bonusLabel() !== null) · <span class="text-success">{{ $pack->bonusLabel() }}</span>@endif</span>
                        </label>
                    @endforeach
                </div>
                @error('pack')<p class="text-small text-danger" role="alert">{{ $message }}</p>@enderror
            </fieldset>

            <fieldset>
                <legend class="sr-only">How to pay</legend>
                <div class="flex gap-2">
                    @if (in_array('card', $methods, true))
                        <label class="{{ $method }}"><input type="radio" name="method" value="card" class="sr-only" @checked($chosenMethod === 'card') required>Card</label>
                    @endif
                    @if (in_array('crypto', $methods, true))
                        <label class="{{ $method }}"><input type="radio" name="method" value="crypto" class="sr-only" @checked($chosenMethod === 'crypto') required>Crypto (USDT)</label>
                    @endif
                </div>
                @error('method')<p class="mt-2 text-small text-danger" role="alert">{{ $message }}</p>@enderror
            </fieldset>

            @foreach (['wallet', 'network', 'idempotency_key'] as $field)
                @error($field)<p class="rounded-md bg-surface-raised p-3 text-small text-danger" role="alert">{{ $message }}</p>@enderror
            @endforeach

            <x-ui.button type="submit" variant="brand">Continue to secure checkout</x-ui.button>
            <p class="text-small text-ink-muted">Credits never expire. Card payments go through our payment partner; {{ config('fanfolio.brand.name') }} never sees your card.</p>
        </form>

        <a href="{{ route('account.purchases') }}" class="text-small text-ink-muted underline underline-offset-4 hover:text-ink">Purchases &amp; receipts</a>
    </div>
</x-layouts.app>
