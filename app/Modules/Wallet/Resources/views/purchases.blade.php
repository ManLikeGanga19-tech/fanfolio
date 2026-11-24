{{-- Canvas FanProfile · Purchases & receipts (M3 PR 2). Top-ups now; subscriptions, unlocks and tips join as
     M5 and M6 post them. "Download statement" is a printable page (D-22). --}}
@php
    $tz = config('app.timezone');
@endphp
<x-layouts.app title="Purchases">
    <div class="mx-auto flex max-w-md flex-col gap-4 py-8">
        <div class="flex items-center gap-2">
            <a href="{{ route('account') }}" class="flex size-11 items-center justify-center rounded-md text-ink hover:bg-surface-raised" aria-label="Back to account"><x-ui.icon name="arrow-left" :size="20" /></a>
            <h1 class="text-h2">Purchases</h1>
        </div>

        <nav aria-label="Filter purchases" class="flex flex-wrap gap-2">
            @foreach ($tabs as $key => $label)
                <a href="{{ route('account.purchases', $key === 'all' ? [] : ['tab' => $key]) }}" @if ($active === $key) aria-current="page" @endif
                    @class([
                        'inline-flex h-9 items-center rounded-pill border px-3.5 text-small',
                        'border-brand bg-surface-raised font-semibold text-ink' => $active === $key,
                        'border-border text-ink-muted hover:text-ink' => $active !== $key,
                    ])>{{ $label }}</a>
            @endforeach
        </nav>

        @forelse ($months as $month => $topUps)
            <section aria-labelledby="month-{{ $loop->index }}" class="flex flex-col gap-2">
                <h2 id="month-{{ $loop->index }}" class="text-label tracking-wide text-ink-muted uppercase">{{ $month }} · added {{ number_format($topUps->sum(fn ($t) => $t->totalCredits())) }} credits</h2>
                <ul class="overflow-hidden rounded-lg bg-surface">
                    @foreach ($topUps as $topUp)
                        <li class="border-b border-border last:border-b-0">
                            <a href="{{ route('wallet.topups.show', $topUp->id) }}" class="flex min-h-[60px] items-center gap-3 px-4 py-2.5 text-ink hover:bg-surface-raised">
                                <span class="flex-1">
                                    <span class="block">Top-up · {{ $topUp->payment_hint ?? ($topUp->method->value === 'crypto' ? 'Crypto (USDT)' : 'Card') }}</span>
                                    <span class="block text-small text-ink-muted">{{ $topUp->paid_at?->setTimezone($tz)->format('j M') }} · {{ $topUp->reference() }}</span>
                                </span>
                                <span class="font-semibold text-success">+{{ number_format($topUp->totalCredits()) }}</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </section>
        @empty
            <p class="rounded-lg bg-surface p-4 text-ink-muted">{{ $active === 'all' || $active === 'topup' ? 'No purchases yet.' : 'Nothing here yet.' }}</p>
        @endforelse

        <x-ui.button :href="route('account.purchases.statement')">Download statement</x-ui.button>
        <p class="text-center text-small text-ink-muted">Something wrong with a charge? <a href="mailto:{{ $support }}" class="text-brand underline underline-offset-4">Get help</a> before contacting your bank. It's faster.</p>
    </div>
</x-layouts.app>
