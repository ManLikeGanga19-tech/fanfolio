{{-- Canvas board AdminOverview. Admins see the money tiles and the sales chart (see App\Filament\Admin\Overview). --}}
<x-filament-panels::page>
    <div class="ff-admin flex flex-col gap-6">
        <div role="group" aria-label="Period" class="flex flex-wrap gap-2">
            @foreach ($ranges as $key => $label)
                <button
                    type="button"
                    wire:click="setRange('{{ $key }}')"
                    aria-pressed="{{ $active === $key ? 'true' : 'false' }}"
                    @class([
                        'inline-flex h-9 items-center rounded-pill px-3.5 text-label',
                        'bg-surface-raised text-ink' => $active === $key,
                        'border border-border text-ink-muted hover:text-ink' => $active !== $key,
                    ])
                >{{ $label }}</button>
            @endforeach
        </div>

        @php
            $signUpsTile = '<div class="flex flex-col gap-2 rounded-lg border border-border bg-surface p-5"><span class="text-small text-ink-muted">Sign-ups</span>'
                .'<span class="font-display text-h1">'.e(number_format($signUps)).'</span>'
                .'<span class="text-small '.($signUpsChange['tone'] === 'up' ? 'text-success' : 'text-ink-muted').'">'.e($signUpsChange['text']).'</span></div>';
        @endphp
        {{-- Admins: the canvas order (sales, creators, cases, payouts), sign-ups last. Moderators see no money. --}}
        <div class="grid grid-cols-[repeat(auto-fit,minmax(180px,1fr))] gap-4">
            @if ($sales !== null)
                <div class="flex flex-col gap-2 rounded-lg border border-border bg-surface p-5">
                    <span class="text-small text-ink-muted">Sales (GMV)</span>
                    <span class="font-display text-h1">{{ \App\Filament\Admin\Overview::dollars($sales) }}</span>
                    <span @class(['text-small', 'text-success' => $salesChange['tone'] === 'up', 'text-ink-muted' => $salesChange['tone'] !== 'up'])>{{ $salesChange['text'] }}</span>
                </div>
            @else
                {!! $signUpsTile !!}

                <div class="flex flex-col gap-2 rounded-lg border border-border bg-surface p-5">
                    <span class="text-small text-ink-muted">Passed the 18+ check</span>
                    <span class="font-display text-h1">{{ $verifiedShare === null ? '—' : $verifiedShare.'%' }}</span>
                    <span class="text-small text-ink-muted">{{ $verifiedShare === null ? 'No sign-ups yet' : number_format($verified).' of '.number_format($signUps).' new accounts' }}</span>
                </div>
            @endif

            <a href="{{ $approvalsUrl }}" class="flex flex-col gap-2 rounded-lg border border-border bg-surface p-5 text-ink hover:border-border-strong">
                <span class="text-small text-ink-muted">Creators awaiting review</span>
                <span class="font-display text-h1">{{ number_format($waiting) }}</span>
                <span @class(['text-small', 'text-warning' => $oldestIsLate, 'text-ink-muted' => ! $oldestIsLate])>{{ $oldestWaiting === null ? 'None waiting' : 'Oldest '.$oldestWaiting }}</span>
            </a>

            <a href="{{ $moderationUrl }}" class="flex flex-col gap-2 rounded-lg border border-border bg-surface p-5 text-ink hover:border-border-strong">
                <span class="text-small text-ink-muted">Open cases</span>
                <span class="font-display text-h1">{{ number_format($cases) }}</span>
                <span @class(['text-small', 'text-danger' => $urgentCases > 0, 'text-ink-muted' => $urgentCases === 0])>{{ $urgentCases > 0 ? $urgentCases.' may involve a minor' : ($oldestCase === null ? 'None open' : 'Oldest '.$oldestCase) }}</span>
            </a>

            @if ($payouts !== null)
                <a href="{{ $payoutsUrl }}" class="flex flex-col gap-2 rounded-lg border border-border bg-surface p-5 text-ink hover:border-border-strong">
                    <span class="text-small text-ink-muted">Payouts to review</span>
                    <span class="font-display text-h1">{{ \App\Filament\Admin\Overview::dollars($payouts['amount']) }}</span>
                    <span class="text-small text-ink-muted">{{ $payouts['count'] === 0 ? 'None waiting' : $payouts['count'].' '.($payouts['count'] === 1 ? 'request' : 'requests') }}</span>
                </a>

                {{-- Canvas "Chargeback rate (30d) · Below 0.75% target" (spec alert threshold). --}}
                @php
                    $rateTone = \App\Modules\Wallet\Filament\Admin\Disputes::rateTone($chargebacks['bps']);
                @endphp
                <a href="{{ $disputesUrl }}" class="flex flex-col gap-2 rounded-lg border border-border bg-surface p-5 text-ink hover:border-border-strong">
                    <span class="text-small text-ink-muted">Chargeback rate (30d)</span>
                    <span class="font-display text-h1">{{ \App\Modules\Wallet\Filament\Admin\Disputes::percent($chargebacks['bps']) }}</span>
                    <span @class(['text-small', 'text-success' => $rateTone === 'ok', 'text-warning' => $rateTone === 'warning', 'text-danger' => $rateTone === 'danger'])>{{ $rateTone === 'ok' ? 'Below 0.75% target' : ($rateTone === 'warning' ? 'Above 0.75% target' : 'Near the 1% processors act on') }}</span>
                </a>

                {!! $signUpsTile !!}
            @else
                <div class="flex flex-col gap-2 rounded-lg border border-border bg-surface p-5">
                    <span class="text-small text-ink-muted">Creators approved</span>
                    <span class="font-display text-h1">{{ number_format($approved) }}</span>
                    <span class="text-small text-ink-muted">{{ $ranges[$active] === 'Today' ? 'Today' : 'Last '.$ranges[$active] }}</span>
                </div>
            @endif
        </div>

        <div class="flex flex-wrap items-start gap-4">
            <section aria-labelledby="overview-chart" class="flex min-w-0 flex-[2_1_480px] flex-col gap-4 rounded-lg border border-border bg-surface p-6">
                <h2 id="overview-chart" class="font-display text-h3">{{ $chartTitle }}, last {{ \App\Filament\Admin\Overview::CHART_DAYS }} days{{ $sales !== null ? ' (USD)' : '' }}</h2>
                @if ($chart['total'] === 0)
                    <p class="text-small text-ink-muted">No {{ mb_strtolower($chartTitle) }} in the last {{ \App\Filament\Admin\Overview::CHART_DAYS }} days.</p>
                @else
                    <svg role="img" aria-label="{{ $chart['label'] }}" viewBox="0 0 {{ count($chart['bars']) * 20 }} 100" preserveAspectRatio="none" class="h-44 w-full border-b border-border">
                        @foreach ($chart['bars'] as $i => $bar)
                            <rect x="{{ $i * 20 + 3 }}" y="{{ 100 - $bar['height'] }}" width="14" height="{{ $bar['height'] }}" rx="1.5" class="fill-brand"><title>{{ $bar['day'] }}: {{ $bar['value'] }}</title></rect>
                        @endforeach
                    </svg>
                    <div class="flex justify-between text-small text-ink-muted" aria-hidden="true">
                        @foreach ($chart['ticks'] as $tick)
                            <span>{{ $tick }}</span>
                        @endforeach
                    </div>
                @endif
            </section>

            @if ($health !== null)
                <section aria-labelledby="system-health" class="flex min-w-0 flex-[1_1_300px] flex-col rounded-lg border border-border bg-surface p-6">
                    <h2 id="system-health" class="font-display text-h3">System health</h2>
                    <ul class="mt-2">
                        @foreach ($health as $check)
                            <li class="flex items-start gap-3 border-b border-border py-3 last:border-b-0">
                                <span @class([
                                    'mt-1.5 size-2.5 flex-none rounded-full',
                                    'bg-success' => $check->level === \App\Support\Health\HealthCheck::OK,
                                    'bg-warning' => $check->level === \App\Support\Health\HealthCheck::WARNING,
                                    'bg-danger' => $check->level === \App\Support\Health\HealthCheck::PROBLEM,
                                ]) aria-hidden="true"></span>
                                <div class="min-w-0 flex-1">
                                    <p class="font-semibold">
                                        {{ $check->title }}
                                        <span class="sr-only">: {{ ['ok' => 'normal', 'warning' => 'warning', 'problem' => 'problem'][$check->level] }}</span>
                                    </p>
                                    <p class="text-small text-ink-muted">{{ $check->detail }}</p>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif
        </div>
    </div>
</x-filament-panels::page>
