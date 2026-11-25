{{-- Canvas board AdminDisputes (see App\Modules\Wallet\Filament\Admin\Disputes). The refund section below the board is not drawn on the canvas (plan M3 PR 6). --}}
@php
    use App\Modules\Wallet\Enums\DisputeStatus;
    use App\Modules\Wallet\Filament\Admin\Disputes;
    use App\Modules\Wallet\Filament\Admin\PayoutReviews;
    $zone = \Filament\Support\Facades\FilamentTimezone::get();
    $tones = ['ok' => 'text-success', 'warning' => 'text-warning', 'danger' => 'text-danger', 'muted' => 'text-ink-muted'];
    $marks = ['ok' => '✓', 'warning' => '!', 'danger' => '✕', 'muted' => '·'];
    $words = ['ok' => 'Supports us', 'warning' => 'Weak', 'danger' => 'Against us', 'muted' => 'Note'];
    $rateTone = Disputes::rateTone($tiles['rate']['bps']);
@endphp
<x-filament-panels::page>
    <div class="ff-admin flex flex-col gap-6">
        <div class="grid grid-cols-[repeat(auto-fit,minmax(180px,1fr))] gap-4">
            <div class="flex flex-col gap-2 rounded-lg border border-border bg-surface p-5">
                <span class="text-small text-ink-muted">Chargeback rate (30d)</span>
                <span class="font-display text-h1 {{ $tones[$rateTone] }}">{{ Disputes::percent($tiles['rate']['bps']) }}</span>
                <span class="text-small text-ink-muted">{{ $tiles['rate']['chargebacks'] }} of {{ number_format($tiles['rate']['topups']) }} top-ups · processors act near 1%</span>
            </div>
            <div class="flex flex-col gap-2 rounded-lg border border-border bg-surface p-5">
                <span class="text-small text-ink-muted">Open disputes</span>
                <span class="font-display text-h1">{{ $tiles['open'] }}</span>
                <span @class(['text-small', 'text-warning' => $tiles['urgent'] > 0, 'text-ink-muted' => $tiles['urgent'] === 0])>{{ $tiles['urgent'] > 0 ? $tiles['urgent'].' due in '.Disputes::URGENT_HOURS.'h' : 'None due in '.Disputes::URGENT_HOURS.'h' }}</span>
            </div>
            <div class="flex flex-col gap-2 rounded-lg border border-border bg-surface p-5">
                <span class="text-small text-ink-muted">Won (90d)</span>
                <span class="font-display text-h1">{{ $tiles['decided'] === 0 ? '–' : intdiv($tiles['won'] * 100, $tiles['decided']).'%' }}</span>
                <span class="text-small text-ink-muted">{{ $tiles['decided'] === 0 ? 'No decisions yet' : $tiles['won'].' of '.$tiles['decided'] }}</span>
            </div>
            <div class="flex flex-col gap-2 rounded-lg border border-border bg-surface p-5">
                <span class="text-small text-ink-muted">Fans frozen</span>
                <span class="font-display text-h1">{{ $tiles['frozen'] }}</span>
                <span class="text-small text-ink-muted">Wallets locked during dispute</span>
            </div>
        </div>

        <div class="flex flex-wrap items-start gap-4">
            <section aria-label="Disputes" class="min-w-0 flex-[2_1_520px] overflow-x-auto rounded-lg border border-border bg-surface px-3 py-2">
                <div role="tablist" aria-label="Which disputes" class="flex gap-2 px-3 pb-1 pt-2">
                    @foreach (['open' => 'Open', 'decided' => 'Decided'] as $key => $label)
                        <button type="button" role="tab" wire:click="showTab('{{ $key }}')" aria-selected="{{ $tab === $key ? 'true' : 'false' }}" @class(['inline-flex min-h-11 items-center rounded-md px-3 text-sm font-semibold', 'bg-surface-raised text-ink' => $tab === $key, 'text-ink-muted hover:text-ink' => $tab !== $key])>{{ $label }}</button>
                    @endforeach
                </div>
                @if ($queue->isEmpty())
                    <p class="p-3 text-ink-muted">{{ $tab === 'open' ? 'No open disputes. Chargebacks appear here as the processor reports them, soonest deadline first.' : 'No decided disputes yet.' }}</p>
                @else
                    <table class="w-full border-collapse text-sm">
                        <caption class="sr-only">{{ $tab === 'open' ? 'Open disputes, soonest deadline first.' : 'Decided disputes, latest first.' }} Choose a case to see its evidence.</caption>
                        <thead>
                            <tr>
                                @foreach (['Case', 'Fan', 'Amount', 'Reason', $tab === 'open' ? 'Respond by' : 'Outcome'] as $heading)
                                    <th scope="col" class="whitespace-nowrap border-b border-border px-3 py-2.5 text-left text-label uppercase text-ink-muted">{{ $heading }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($queue as $dispute)
                                @php
                                    $due = $dispute->respond_by;
                                @endphp
                                <tr wire:key="dispute-{{ $dispute->id }}" @class(['bg-surface-raised' => $detail?->id === $dispute->id])>
                                    <td class="whitespace-nowrap border-b border-border p-3">
                                        <button type="button" wire:click="select('{{ $dispute->id }}')" aria-pressed="{{ $detail?->id === $dispute->id ? 'true' : 'false' }}" class="min-h-11 text-left font-semibold text-ink underline-offset-4 hover:underline">{{ $dispute->gateway_case_ref }}</button>
                                    </td>
                                    <td class="whitespace-nowrap border-b border-border p-3">{{ '@'.($names[$dispute->fan_id] ?? 'unknown') }}</td>
                                    <td class="whitespace-nowrap border-b border-border p-3">{{ PayoutReviews::money($dispute->amount_minor) }}</td>
                                    <td class="whitespace-nowrap border-b border-border p-3">{{ $dispute->reasonLabel() }}</td>
                                    <td class="whitespace-nowrap border-b border-border p-3">
                                        @if ($tab === 'open')
                                            <span @class(['inline-flex h-6 items-center whitespace-nowrap rounded-pill bg-surface-raised px-2.5 text-label',
                                                'text-danger' => $due->isPast(),
                                                'text-warning' => ! $due->isPast() && $due->lte(now()->addHours(Disputes::URGENT_HOURS)),
                                                'text-ink' => $due->gt(now()->addHours(Disputes::URGENT_HOURS))])>
                                                {{ $due->isPast() ? 'Past due' : $due->setTimezone($zone)->format('j M') }}{{ $dispute->status === DisputeStatus::EvidenceSent ? ' · evidence sent' : '' }}
                                            </span>
                                        @else
                                            <span @class(['inline-flex h-6 items-center whitespace-nowrap rounded-pill bg-surface-raised px-2.5 text-label',
                                                'text-success' => $dispute->status === DisputeStatus::Won,
                                                'text-danger' => $dispute->status === DisputeStatus::Lost,
                                                'text-ink' => $dispute->status === DisputeStatus::Accepted])>{{ $dispute->status->label() }}</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    @if ($queue->lastPage() > 1)
                        <nav aria-label="Pages" class="flex items-center justify-between gap-3 px-3 py-3 text-small text-ink-muted">
                            <span>{{ Disputes::pageLabel($queue) }}</span>
                            <span class="flex gap-2">
                                <button type="button" wire:click="goToPage({{ $queue->currentPage() - 1 }})" @disabled($queue->onFirstPage()) class="inline-flex h-9 items-center rounded-md border border-border px-3 text-ink disabled:opacity-50">Previous</button>
                                <button type="button" wire:click="goToPage({{ $queue->currentPage() + 1 }})" @disabled(! $queue->hasMorePages()) class="inline-flex h-9 items-center rounded-md border border-border px-3 text-ink disabled:opacity-50">Next</button>
                            </span>
                        </nav>
                    @endif
                @endif
            </section>

            <section aria-label="Dispute detail" class="flex min-w-0 flex-[1_1_300px] flex-col gap-3 rounded-lg border border-border bg-surface p-6">
                @if ($detail === null)
                    <h2 class="font-display text-h3">Dispute detail</h2>
                    <p class="text-small text-ink-muted">Choose a case to see its evidence.</p>
                @else
                    @php
                        $recovery = $detail->recovery;
                    @endphp
                    <h2 class="font-display text-h3">{{ $detail->gateway_case_ref }} · {{ PayoutReviews::money($detail->amount_minor) }} top-up</h2>
                    <p class="text-small text-ink-muted">
                        {{ collect([$topUp?->payment_hint, $topUp === null ? null : number_format($topUp->totalCredits()).' credits', $topUp?->paid_at?->setTimezone($zone)->format('j M')])->filter()->implode(' · ') }}
                        · reason: {{ $detail->reasonSentence() }}
                    </p>
                    <p class="text-small text-ink-muted">{{ '@'.($names[$detail->fan_id] ?? 'unknown') }} · respond by {{ $detail->respond_by->setTimezone($zone)->format('j M, H:i') }}</p>

                    <p class="mt-2 text-label uppercase text-ink-muted">
                        @if ($detail->status === DisputeStatus::Open) Evidence to send
                        @elseif ($evidence === []) No evidence sent
                        @else Evidence sent {{ $detail->evidence_sent_at?->setTimezone($zone)->format('j M, H:i') }}
                        @endif
                    </p>
                    @if ($evidence !== [])
                        <ul>
                            @foreach ($evidence as $line)
                                <li class="flex gap-2.5 border-b border-border py-2 text-sm last:border-b-0">
                                    <span class="w-4 flex-none text-center font-semibold {{ $tones[$line['tone']] }}" aria-hidden="true">{{ $marks[$line['tone']] }}</span>
                                    <span><span class="sr-only">{{ $words[$line['tone']] }}: </span>{{ $line['text'] }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    <div class="rounded-md bg-surface-raised p-3 text-sm">
                        <p class="font-semibold">Taken back when it arrived</p>
                        <ul class="mt-1 flex flex-col gap-0.5 text-small text-ink-muted">
                            <li>{{ number_format($recovery['unspent_minor'] ?? 0) }} unspent credits from the wallet</li>
                            @if (($recovery['clawed_minor'] ?? 0) > 0)
                                <li>{{ PayoutReviews::money($recovery['clawed_minor']) }} from {{ count($recovery['sales']) }} {{ count($recovery['sales']) === 1 ? 'sale' : 'sales' }} (newest first, D-20)</li>
                            @endif
                            @if (($recovery['bonus_minor'] ?? 0) > 0)
                                <li>{{ number_format($recovery['bonus_minor']) }} bonus credits reversed</li>
                            @endif
                            @if (($recovery['written_off_minor'] ?? 0) > 0)
                                <li class="text-warning">{{ PayoutReviews::money($recovery['written_off_minor']) }} could not be recovered (absorbed by FanFolio)</li>
                            @endif
                        </ul>
                    </div>

                    @error('dispute')<p class="text-small text-danger">{{ $message }}</p>@enderror
                    @error('gateway')<p class="text-small text-danger">{{ $message }}</p>@enderror
                    @error('staff')<p class="text-small text-danger">{{ $message }}</p>@enderror
                    @error('wallet')<p class="text-small text-danger">{{ $message }}</p>@enderror

                    @if ($detail->status === DisputeStatus::Open)
                        <div class="flex flex-wrap gap-2.5">
                            {{ $this->submitEvidenceAction }}
                            {{ $this->acceptAction }}
                        </div>
                    @elseif ($detail->status === DisputeStatus::EvidenceSent)
                        <p class="text-small text-ink-muted">Evidence sent. Waiting for the card network's decision.</p>
                    @else
                        <p class="text-small text-ink-muted">{{ $detail->status->label() }} on {{ $detail->decided_at?->setTimezone($zone)->format('j M') }}.
                            @if ($detail->status === DisputeStatus::Won) The credits and sales taken back were returned. @endif
                        </p>
                    @endif

                    @if ($frozen)
                        <p class="text-small text-warning">This fan's wallet is frozen: they cannot top up or spend.</p>
                        @if ($canLift)
                            <div>{{ $this->liftFreezeAction }}</div>
                        @else
                            <p class="text-small text-ink-muted">The freeze can be lifted once no dispute of this fan is open.</p>
                        @endif
                    @else
                        <p class="text-small text-ink-muted">This fan's wallet is not frozen.</p>
                    @endif
                @endif
            </section>
        </div>

        @if ($canRefund)
            <section aria-labelledby="refund-heading" class="flex flex-col gap-3 rounded-lg border border-border bg-surface p-6">
                <div>
                    <h2 id="refund-heading" class="font-display text-h3">Refund a sale</h2>
                    <p class="text-small text-ink-muted">Return one sale to the fan's credits. Credits are never paid out to cards. See [CREDIT REFUND POLICY].</p>
                </div>
                <form wire:submit="$refresh" class="flex flex-wrap items-end gap-2.5">
                    <label class="flex flex-col gap-1 text-sm">
                        <span class="font-semibold">Fan's username</span>
                        <input type="search" wire:model="refundFan" placeholder="@username" autocomplete="off" class="h-11 w-64 rounded-md border border-border-strong bg-bg px-3 text-ink">
                    </label>
                    <button type="submit" class="inline-flex h-11 items-center rounded-md border border-border-strong px-4 font-semibold text-ink">Find sales</button>
                </form>

                @if ($refund['searched'] && $refund['fan'] === null)
                    <p class="text-small text-ink-muted">No fan with that username.</p>
                @elseif ($refund['fan'] !== null && $refund['sales'] === [])
                    <p class="text-small text-ink-muted">{{ '@'.$refund['username'] }} has not bought anything yet.</p>
                @elseif ($refund['sales'] !== [])
                    <div class="overflow-x-auto">
                        <table class="w-full border-collapse text-sm">
                            <caption class="sr-only">Last 20 sales of {{ '@'.$refund['username'] }}, newest first.</caption>
                            <thead>
                                <tr>
                                    @foreach (['Date', 'Creator', 'Kind', 'Amount', 'Status', ''] as $heading)
                                        <th scope="col" class="whitespace-nowrap border-b border-border px-3 py-2.5 text-left text-label uppercase text-ink-muted">@if ($heading === '')<span class="sr-only">Action</span>@else{{ $heading }}@endif</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($refund['sales'] as $sale)
                                    <tr wire:key="sale-{{ $sale->transaction_id }}">
                                        <td class="whitespace-nowrap border-b border-border p-3">{{ \Carbon\CarbonImmutable::parse($sale->created_at, 'UTC')->setTimezone($zone)->format('j M, H:i') }}</td>
                                        <td class="whitespace-nowrap border-b border-border p-3">{{ '@'.($refund['creatorNames'][$sale->creator_id] ?? 'unknown') }}</td>
                                        <td class="whitespace-nowrap border-b border-border p-3">{{ ucfirst($sale->kind) }}</td>
                                        <td class="whitespace-nowrap border-b border-border p-3">{{ number_format((int) $sale->gross_minor) }} credits</td>
                                        <td class="whitespace-nowrap border-b border-border p-3">
                                            @if ($sale->refunded_at !== null){{ $sale->dispute_id !== null ? 'Charged back' : 'Refunded' }}
                                            @elseif ($sale->released_at !== null) Paid to creator
                                            @else On 7-day hold
                                            @endif
                                        </td>
                                        <td class="whitespace-nowrap border-b border-border p-3 text-right">
                                            @if ($sale->refunded_at === null){{ ($this->refundSaleAction)(['sale' => $sale->transaction_id]) }}@endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @error('sale')<p class="text-small text-danger">{{ $message }}</p>@enderror
                    @error('reason')<p class="text-small text-danger">{{ $message }}</p>@enderror
                @endif
            </section>
        @endif
    </div>
</x-filament-panels::page>
