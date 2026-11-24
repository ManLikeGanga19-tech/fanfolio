{{-- Decision D-22: a printable statement of the purchases list; the browser saves it as PDF. --}}
@php
    use App\Modules\Wallet\Actions\WalletDisplayService;
    $tz = config('app.timezone');
    $cell = 'border-b border-border px-2 py-2 text-left';
@endphp
<x-layouts.app title="Statement">
    <article class="mx-auto flex max-w-2xl flex-col gap-4 py-8">
        <h1 class="text-h2">Statement</h1>
        <p class="text-ink-muted">{{ config('fanfolio.brand.name') }} · shown on bank statements as {{ $descriptor }}</p>
        @if ($topUps->isEmpty())
            <p class="text-ink-muted">No purchases yet.</p>
        @else
            <table class="w-full border-collapse text-sm">
                <thead>
                    <tr class="text-label text-ink-muted uppercase">
                        <th scope="col" class="{{ $cell }}">Date</th>
                        <th scope="col" class="{{ $cell }}">Item</th>
                        <th scope="col" class="{{ $cell }}">Reference</th>
                        <th scope="col" class="{{ $cell }} text-right">Paid</th>
                        <th scope="col" class="{{ $cell }} text-right">Credits</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($topUps as $topUp)
                        <tr>
                            <td class="{{ $cell }}">{{ $topUp->paid_at?->setTimezone($tz)->format('j M Y') }}</td>
                            <td class="{{ $cell }}">Top-up · {{ $topUp->payment_hint ?? ucfirst($topUp->method->value) }}</td>
                            <td class="{{ $cell }}">{{ $topUp->reference() }}</td>
                            <td class="{{ $cell }} text-right">{{ WalletDisplayService::dollars($topUp->amount_minor) }}</td>
                            <td class="{{ $cell }} text-right">+{{ number_format($topUp->totalCredits()) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
        <p class="text-small text-ink-muted print:hidden">Use your browser's Print to save this statement as a PDF.</p>
    </article>
</x-layouts.app>
