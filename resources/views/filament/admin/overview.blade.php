{{-- Canvas board AdminOverview. Money tiles and the sales chart arrive with M3 (see App\Filament\Admin\Overview). --}}
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

        <div class="grid grid-cols-[repeat(auto-fit,minmax(180px,1fr))] gap-4">
            <div class="flex flex-col gap-2 rounded-lg border border-border bg-surface p-5">
                <span class="text-small text-ink-muted">Sign-ups</span>
                <span class="font-display text-h1">{{ number_format($signUps) }}</span>
                <span @class(['text-small', 'text-success' => $signUpsChange['tone'] === 'up', 'text-ink-muted' => $signUpsChange['tone'] !== 'up'])>{{ $signUpsChange['text'] }}</span>
            </div>

            <div class="flex flex-col gap-2 rounded-lg border border-border bg-surface p-5">
                <span class="text-small text-ink-muted">Passed the 18+ check</span>
                <span class="font-display text-h1">{{ $verifiedShare === null ? '—' : $verifiedShare.'%' }}</span>
                <span class="text-small text-ink-muted">{{ $verifiedShare === null ? 'No sign-ups yet' : number_format($verified).' of '.number_format($signUps).' new accounts' }}</span>
            </div>

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

            <div class="flex flex-col gap-2 rounded-lg border border-border bg-surface p-5">
                <span class="text-small text-ink-muted">Creators approved</span>
                <span class="font-display text-h1">{{ number_format($approved) }}</span>
                <span class="text-small text-ink-muted">{{ $ranges[$active] === 'Today' ? 'Today' : 'Last '.$ranges[$active] }}</span>
            </div>
        </div>

        <div class="flex flex-wrap items-start gap-4">
            <section aria-labelledby="signups-chart" class="flex min-w-0 flex-[2_1_480px] flex-col gap-4 rounded-lg border border-border bg-surface p-6">
                <h2 id="signups-chart" class="font-display text-h3">Sign-ups, last {{ \App\Filament\Admin\Overview::CHART_DAYS }} days</h2>
                @if ($chart['total'] === 0)
                    <p class="text-small text-ink-muted">No sign-ups in the last {{ \App\Filament\Admin\Overview::CHART_DAYS }} days.</p>
                @else
                    <svg role="img" aria-label="{{ $chart['label'] }}" viewBox="0 0 {{ count($chart['bars']) * 20 }} 100" preserveAspectRatio="none" class="h-44 w-full border-b border-border">
                        @foreach ($chart['bars'] as $i => $bar)
                            <rect x="{{ $i * 20 + 3 }}" y="{{ 100 - $bar['height'] }}" width="14" height="{{ $bar['height'] }}" rx="1.5" class="fill-brand"><title>{{ $bar['day'] }}: {{ $bar['count'] }}</title></rect>
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
