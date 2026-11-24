{{-- Decision D-22: a printable receipt; the browser saves it as PDF. Plain and print-friendly. --}}
@php
    use App\Modules\Wallet\Actions\WalletDisplayService;
    $row = 'flex justify-between gap-6 border-b border-border py-2.5';
@endphp
<x-layouts.app title="Receipt {{ $topUp->reference() }}">
    <article class="mx-auto flex max-w-md flex-col gap-4 py-8">
        <h1 class="text-h2">Receipt</h1>
        <p class="text-ink-muted">{{ config('fanfolio.brand.name') }} credits · {{ $topUp->paid_at?->setTimezone(config('app.timezone'))->format('j F Y, H:i T') }}</p>
        <dl>
            <div class="{{ $row }}"><dt class="text-ink-muted">Reference</dt><dd>{{ $topUp->reference() }}</dd></div>
            <div class="{{ $row }}"><dt class="text-ink-muted">Credits</dt><dd>{{ number_format($topUp->credits) }}</dd></div>
            @if ($topUp->bonus_credits > 0)
                <div class="{{ $row }}"><dt class="text-ink-muted">Bonus credits</dt><dd>{{ number_format($topUp->bonus_credits) }}</dd></div>
            @endif
            <div class="{{ $row }}"><dt class="text-ink-muted">Amount paid</dt><dd>{{ WalletDisplayService::dollars($topUp->amount_minor) }} {{ $topUp->currency }}</dd></div>
            <div class="{{ $row }}"><dt class="text-ink-muted">Paid with</dt><dd>{{ $topUp->payment_hint ?? ucfirst($topUp->method->value) }}</dd></div>
            <div class="{{ $row }}"><dt class="text-ink-muted">On your statement</dt><dd>{{ $descriptor }}</dd></div>
        </dl>
        <p class="text-small text-ink-muted">Credits are spent on creators and are not withdrawn. Refunds: [CREDIT REFUND POLICY]</p>
        <p class="text-small text-ink-muted print:hidden">Use your browser's Print to save this receipt as a PDF.</p>
    </article>
</x-layouts.app>
