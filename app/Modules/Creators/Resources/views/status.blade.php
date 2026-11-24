{{-- Screens: Creator onboarding 7 · In review (status), 8 · Action needed (when staff send it back).
     Board 7 says "by email and push": push arrives with the Notifications module, so the copy says email only. --}}
@php
    $steps = [
        ['Submitted', $creator->submitted_at?->format('j M, H:i') ?? '', true],
        ['ID and selfie matched', 'Automatic check passed', true],
        ['Manual review', 'In progress', false],
        ['Approved · start earning', '', false],
    ];
@endphp
<x-layouts.auth title="Creator status">
    <x-slot:actions>
        <span class="text-small font-semibold text-ink-muted">{{ config('fanfolio.brand.name') }} Studio</span>
    </x-slot:actions>

    <div class="flex flex-col gap-8">
        @if (session('status'))
            <p class="rounded-md bg-surface-raised p-4 text-small" role="status">{{ session('status') }}</p>
        @endif

        @switch($state)
            @case('in_review')
                <div class="flex flex-col gap-3">
                    <h1 class="text-h1">We're reviewing your account</h1>
                    <p class="text-ink-muted">Usually under 24 hours. We'll email you as soon as you're approved.</p>
                </div>

                <ol class="flex flex-col gap-3" aria-label="Application progress">
                    @foreach ($steps as [$name, $detail, $done])
                        <li class="flex items-start gap-3 rounded-md bg-surface p-4">
                            <span class="mt-1 flex size-5 shrink-0 items-center justify-center rounded-full {{ $done ? 'bg-brand text-on-brand' : 'border border-border-strong' }}" aria-hidden="true">
                                @if ($done)
                                    <svg class="size-3" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="2"><path d="M2.5 6.5l2.5 2.5 4.5-5"/></svg>
                                @endif
                            </span>
                            <span class="flex flex-col gap-1">
                                <span class="font-semibold">{{ $name }}<span class="sr-only">{{ $done ? ', done' : ', not yet' }}</span></span>
                                @if ($detail !== '')
                                    <span class="text-small text-ink-muted">{{ $detail }}</span>
                                @endif
                            </span>
                        </li>
                    @endforeach
                </ol>

                <div class="flex flex-col gap-3">
                    <h2 class="text-label text-ink-muted">WHILE YOU WAIT</h2>
                    <ul class="flex flex-col gap-2 text-small">
                        <li class="rounded-md bg-surface p-4">Prepare your first 3 posts</li>
                        <li class="rounded-md bg-surface p-4">Set prices &amp; bundles</li>
                    </ul>
                </div>
                @break

            @case('fix')
                {{-- Board 8 · Action needed. --}}
                <div class="flex flex-col gap-3">
                    <h1 class="text-h1">One thing to fix</h1>
                    <p class="text-ink-muted">{{ $creator->fixMessage() }}</p>
                </div>
                <x-ui.card class="flex items-center justify-between gap-4">
                    <span class="flex flex-col gap-1">
                        <span class="font-semibold">{{ $creator->fix_kind->label() }}</span>
                        <span class="text-small text-ink-muted">Takes about 1 minute</span>
                    </span>
                    <span class="text-small font-semibold text-warning">Needed</span>
                </x-ui.card>
                <x-ui.button variant="brand" :href="route($creator->fix_kind->route())">{{ $creator->fix_kind->label() }}</x-ui.button>
                <p class="text-small text-ink-muted">When it's fixed, submit again from Review &amp; submit.</p>
                @break

            @case('approved')
                <div class="flex flex-col gap-3">
                    <h1 class="text-h1">You're approved</h1>
                    <p class="text-ink-muted">You can start posting and earning.</p>
                </div>
                <x-ui.button variant="brand" href="{{ url('/studio') }}">Open Studio</x-ui.button>
                @break

            @case('id_expired')
                <div class="flex flex-col gap-3">
                    <h1 class="text-h1">Your ID has expired</h1>
                    <p class="text-ink-muted">Posting and earning are paused until you verify a valid ID.</p>
                </div>
                <x-ui.button variant="brand" :href="route('creator.kyc')">Verify a new ID</x-ui.button>
                @break

            @case('suspended')
                <div class="flex flex-col gap-3">
                    <h1 class="text-h1">Your creator account is paused</h1>
                    <p class="text-ink-muted">Posting and earning are stopped. Contact support to find out more.</p>
                </div>
                <a href="{{ route('account') }}" class="inline-flex min-h-11 items-center justify-center rounded-md border border-border-strong px-4 font-semibold">Back to your account</a>
                @break

            @case('rejected')
                <div class="flex flex-col gap-3">
                    <h1 class="text-h1">We could not approve your account</h1>
                    <p class="text-ink-muted">If you think this is a mistake, contact support.</p>
                </div>
                <a href="{{ route('account') }}" class="inline-flex min-h-11 items-center justify-center rounded-md border border-border-strong px-4 font-semibold">Back to your account</a>
                @break

            @default
                <div class="flex flex-col gap-3">
                    <h1 class="text-h1">Finish your application</h1>
                    <p class="text-ink-muted">A few steps are left before we can review your account.</p>
                </div>
                <x-ui.button variant="brand" :href="route('creator.review')">Continue</x-ui.button>
        @endswitch
    </div>
</x-layouts.auth>
