{{-- Local and testing only: stands in for the payment processor's dispute desk (see FakeDisputeController). --}}
<x-layouts.app title="Test processor disputes">
    <div class="mx-auto flex max-w-3xl flex-col gap-6 py-10">
        <p class="rounded-md bg-surface-raised p-3 text-small text-warning">Test processor. Nothing here touches a real card or bank.</p>
        <h1 class="text-h2">Disputes desk</h1>
        @if (session('status'))
            <p role="status" class="rounded-md bg-surface-raised p-3 text-small">{{ session('status') }}</p>
        @endif
        @if ($errors->any())
            <p role="alert" class="text-small text-danger">{{ $errors->first() }}</p>
        @endif

        <section aria-labelledby="paid-heading" class="flex flex-col gap-3">
            <h2 id="paid-heading" class="text-h3">Paid top-ups</h2>
            @forelse ($topUps as $topUp)
                <form method="POST" action="{{ route('fake.disputes.chargeback', $topUp->id) }}" class="flex flex-wrap items-end gap-3 rounded-md border border-border p-3">
                    @csrf
                    <p class="min-w-48 flex-1 text-sm">{{ $topUp->reference() }} · {{ \App\Modules\Wallet\Actions\WalletDisplayService::dollars($topUp->amount_minor) }} · {{ $topUp->payment_hint }} · {{ $topUp->paid_at?->format('j M H:i') }}</p>
                    <label class="flex flex-col gap-1 text-small">Reason
                        <select name="reason" class="h-11 rounded-md border border-border-strong bg-bg px-2 text-ink">
                            @foreach (\App\Modules\Wallet\Models\Dispute::REASON_LABELS as $code => $label)
                                <option value="{{ $code }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="flex flex-col gap-1 text-small">3-D Secure
                        <select name="three_ds" class="h-11 rounded-md border border-border-strong bg-bg px-2 text-ink">
                            <option value="passed">Passed</option>
                            <option value="failed">Failed</option>
                            <option value="not_used">Not used</option>
                        </select>
                    </label>
                    <label class="flex flex-col gap-1 text-small">Days to respond
                        <input type="number" name="days" value="10" min="1" max="30" class="h-11 w-20 rounded-md border border-border-strong bg-bg px-2 text-ink">
                    </label>
                    <x-ui.button type="submit">File chargeback</x-ui.button>
                </form>
            @empty
                <p class="text-ink-muted">No paid top-ups yet.</p>
            @endforelse
        </section>

        <section aria-labelledby="open-heading" class="flex flex-col gap-3">
            <h2 id="open-heading" class="text-h3">Open cases</h2>
            @forelse ($disputes as $dispute)
                <form method="POST" action="{{ route('fake.disputes.decide', $dispute->id) }}" class="flex flex-wrap items-center gap-3 rounded-md border border-border p-3">
                    @csrf
                    <p class="min-w-48 flex-1 text-sm">{{ $dispute->gateway_case_ref }} · {{ $dispute->status->label() }}</p>
                    <x-ui.button type="submit" name="outcome" value="won">Bank sides with FanFolio</x-ui.button>
                    <x-ui.button type="submit" name="outcome" value="lost">Bank sides with the card holder</x-ui.button>
                </form>
            @empty
                <p class="text-ink-muted">No open cases.</p>
            @endforelse
        </section>
    </div>
</x-layouts.app>
