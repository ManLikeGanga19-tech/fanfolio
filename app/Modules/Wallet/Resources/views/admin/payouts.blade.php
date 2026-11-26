{{-- Canvas board AdminPayouts; see PayoutReviews. --}}
@php
    use App\Modules\Wallet\Enums\PayoutState;
    use App\Modules\Wallet\Filament\Admin\PayoutReviews;
    $zone = \Filament\Support\Facades\FilamentTimezone::get();
    $tones = ['ok' => 'text-success', 'warning' => 'text-warning', 'danger' => 'text-danger', 'muted' => 'text-ink-muted'];
    $pillTones = ['warning' => 'text-warning', 'danger' => 'text-danger', 'muted' => 'text-ink'];
    $marks =['ok' => '✓', 'warning' => '!', 'danger' => '✕', 'muted' => '·'];
    $words = ['ok' => 'Passed', 'warning' => 'Check', 'danger' => 'Problem', 'muted' => 'Note'];
@endphp
<x-filament-panels::page>
    <div class="ff-admin flex flex-col gap-6">
        <div class="grid grid-cols-[repeat(auto-fit,minmax(180px,1fr))] gap-4">
            <div class="flex flex-col gap-2 rounded-lg border border-border bg-surface p-5">
                <span class="text-small text-ink-muted">Waiting for review</span>
                <span class="font-display text-h1">{{ PayoutReviews::money($tiles['waiting']['amount']) }}</span>
                <span class="text-small text-ink-muted">{{ $tiles['waiting']['count'] }} {{ $tiles['waiting']['count'] === 1 ? 'request' : 'requests' }}</span>
            </div>
            <div class="flex flex-col gap-2 rounded-lg border border-border bg-surface p-5">
                <span class="text-small text-ink-muted">Auto-paid today</span>
                <span class="font-display text-h1">{{ PayoutReviews::money($tiles['autoPaid']['amount']) }}</span>
                <span class="text-small text-ink-muted">{{ $tiles['autoPaid']['count'] }} {{ $tiles['autoPaid']['count'] === 1 ? 'payout' : 'payouts' }}</span>
            </div>
            <div class="flex flex-col gap-2 rounded-lg border border-border bg-surface p-5">
                <span class="text-small text-ink-muted">Failed payouts (7 days)</span>
                <span class="font-display text-h1">{{ $tiles['failed'] }}</span>
                <span @class(['text-small', 'text-warning' => $tiles['failed'] > 0, 'text-ink-muted' => $tiles['failed'] === 0])>{{ $tiles['failed'] > 0 ? 'Provider refused · returned to earnings' : 'None' }}</span>
            </div>
            <div class="flex flex-col gap-2 rounded-lg border border-border bg-surface p-5">
                <span class="text-small text-ink-muted">Payout clearing balance</span>
                @if ($tiles['clearing']['reconciled'])
                    <span class="font-display text-h1 text-success">Reconciled</span>
                    <span class="text-small text-ink-muted">{{ PayoutReviews::money($tiles['clearing']['held']) }} held for {{ $tiles['clearing']['inProgress'] }} in progress · checked {{ now()->setTimezone($zone)->format('H:i') }}</span>
                @else
                    <span class="font-display text-h1 text-danger">Out by {{ PayoutReviews::money(abs($tiles['clearing']['difference'])) }}</span>
                    <span class="text-small text-danger">Clearing holds {{ PayoutReviews::money($tiles['clearing']['held']) }}; payouts in progress need a different amount. Tell engineering.</span>
                @endif
            </div>
        </div>

        <div class="flex flex-wrap items-start gap-4">
            <section aria-label="Payouts waiting" class="min-w-0 flex-[2_1_520px] overflow-x-auto rounded-lg border border-border bg-surface px-3 py-2">
                @if ($queue->isEmpty())
                    <p class="p-3 text-ink-muted">Nothing waiting. Payouts that need a person appear here, oldest first.</p>
                @else
                    <table class="w-full border-collapse text-sm">
                        <caption class="sr-only">Payouts waiting for review, oldest first. Choose a creator to see the checks.</caption>
                        <thead>
                            <tr>
                                @foreach (['Creator', 'Amount', 'Method', 'Flags', 'Requested'] as $heading)
                                    <th scope="col" class="whitespace-nowrap border-b border-border px-3 py-2.5 text-left text-label uppercase text-ink-muted">{{ $heading }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($queue as $payout)
                                <tr wire:key="payout-{{ $payout->id }}" @class(['bg-surface-raised' => $detail?->id === $payout->id])>
                                    <td class="whitespace-nowrap border-b border-border p-3">
                                        <button type="button" wire:click="select('{{ $payout->id }}')" aria-pressed="{{ $detail?->id === $payout->id ? 'true' : 'false' }}" class="min-h-11 text-left font-semibold text-ink underline-offset-4 hover:underline">
                                            {{ '@'.($names[$payout->creator_id] ?? 'unknown') }}
                                        </button>
                                    </td>
                                    <td class="whitespace-nowrap border-b border-border p-3">{{ PayoutReviews::money($payout->amount_minor) }}</td>
                                    <td class="whitespace-nowrap border-b border-border p-3">{{ $payout->display_hint }}</td>
                                    <td class="border-b border-border p-3">
                                        <span class="flex flex-wrap gap-1.5">
                                            @foreach (PayoutReviews::flags($payout) as $pill)
                                                <span class="inline-flex h-6 items-center whitespace-nowrap rounded-pill bg-surface-raised px-2.5 text-label {{ $pillTones[$pill['tone']] }}">{{ $pill['text'] }}</span>
                                            @endforeach
                                        </span>
                                    </td>
                                    <td class="whitespace-nowrap border-b border-border p-3">{{ $payout->requested_at->shortRelativeDiffForHumans() }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    @if ($queue->lastPage() > 1)
                        <nav aria-label="Pages" class="flex items-center justify-between gap-3 px-3 py-3 text-small text-ink-muted">
                            <span>{{ PayoutReviews::pageLabel($queue) }}</span>
                            <span class="flex gap-2">
                                <button type="button" wire:click="goToPage({{ $queue->currentPage() - 1 }})" @disabled($queue->onFirstPage()) class="inline-flex h-9 items-center rounded-md border border-border px-3 text-ink disabled:opacity-50">Previous</button>
                                <button type="button" wire:click="goToPage({{ $queue->currentPage() + 1 }})" @disabled(! $queue->hasMorePages()) class="inline-flex h-9 items-center rounded-md border border-border px-3 text-ink disabled:opacity-50">Next</button>
                            </span>
                        </nav>
                    @endif
                @endif
            </section>

            <section aria-label="Payout detail" class="flex min-w-0 flex-[1_1_300px] flex-col gap-3 rounded-lg border border-border bg-surface p-6">
                @if ($detail === null)
                    <h2 class="font-display text-h3">Payout detail</h2>
                    <p class="text-small text-ink-muted">Choose a payout to see its checks.</p>
                @else
                    <h2 class="font-display text-h3">{{ '@'.($names[$detail->creator_id] ?? 'unknown') }} · {{ PayoutReviews::money($detail->amount_minor) }}</h2>
                    <p class="text-small text-ink-muted">To {{ $detail->display_hint }} · requested {{ $detail->requested_at->setTimezone($zone)->format('j M, H:i') }}</p>

                    <ul>
                        @foreach ($checks as $check)
                            <li class="flex gap-2.5 border-b border-border py-2 text-sm last:border-b-0">
                                <span class="w-4 flex-none text-center font-semibold {{ $tones[$check['tone']] }}" aria-hidden="true">{{ $marks[$check['tone']] }}</span>
                                <span><span class="sr-only">{{ $words[$check['tone']] }}: </span>{{ $check['text'] }}</span>
                            </li>
                        @endforeach
                    </ul>

                    @if ($detail->status === PayoutState::Held || ($detail->reviewed_by !== null && $detail->review_note !== null))
                        <div class="rounded-md bg-surface-raised p-3 text-sm">
                            <p class="font-semibold">{{ $detail->status === PayoutState::Held ? 'On hold' : 'Reviewer\'s note' }}</p>
                            <p class="text-small text-ink-muted">{{ $reviewer !== null ? '@'.$reviewer : 'Staff' }} · {{ $detail->reviewed_at?->setTimezone($zone)->format('j M, H:i') }}</p>
                            @if ($detail->review_note !== null)<p class="mt-1">{{ $detail->review_note }}</p>@endif
                        </div>
                    @endif

                    @if ($waiting)
                        {{-- The note is asked for in each decision's dialog (needed to hold or decline). --}}
                        @error('payout')<p class="text-small text-danger">{{ $message }}</p>@enderror
                        @error('staff')<p class="text-small text-danger">{{ $message }}</p>@enderror

                        <div class="flex flex-wrap gap-2.5">
                            {{ $this->approveAction }}
                            @if ($detail->status === PayoutState::Review)
                                {{ $this->holdAction }}
                            @endif
                            {{ $this->declineAction }}
                        </div>
                        <p class="text-small text-ink-muted">Holding asks the creator for more information and keeps the funds locked.</p>
                    @else
                        <p class="text-small text-ink-muted">{{ $detail->status->label() }}. This payout is no longer waiting for review.</p>
                    @endif
                @endif
            </section>
        </div>
    </div>
</x-filament-panels::page>
