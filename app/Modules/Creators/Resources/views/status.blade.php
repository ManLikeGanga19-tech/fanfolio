{{-- Screens: Creator onboarding 7 · In review (status), 8 · Action needed (canvas board Main · 7–8).
     Board 7 says "by email and push": push arrives with the Notifications module, so the copy says email only.
     "While you wait" items stay text until those Studio features exist (M4, M5). --}}
@php
    $steps = [
        ['Submitted', $creator->submitted_at?->format('j M, H:i') ?? '', 'done'],
        ['ID and selfie matched', 'Automatic check passed', 'done'],
        ['Manual review', 'In progress', 'current'],
        ['Approved · start earning', '', 'next'],
    ];
@endphp
<x-layouts.auth title="Creator status">
    <x-slot:actions>
        <span class="inline-flex h-6 items-center rounded-full bg-surface-raised px-2.5 text-label text-brand">Studio</span>
    </x-slot:actions>

    <div class="flex flex-col gap-6">
        @if (session('status'))
            <p class="rounded-md bg-surface-raised p-4 text-small" role="status">{{ session('status') }}</p>
        @endif

        @switch($state)
            @case('in_review')
                <x-ui.illustration name="in-review" />
                <div class="flex flex-col gap-3">
                    <h1 class="text-h2">We're reviewing your account</h1>
                    <p class="text-ink-muted">Usually under 24 hours. We'll email you as soon as you're approved.</p>
                </div>

                <x-ui.card>
                    <ol class="flex flex-col gap-4" aria-label="Application progress">
                        @foreach ($steps as [$name, $detail, $phase])
                            <li class="flex gap-3">
                                @if ($phase === 'done')
                                    <span class="flex size-6 shrink-0 items-center justify-center rounded-full bg-success text-on-brand" aria-hidden="true"><x-ui.icon name="check" :size="14" :stroke="2.5" /></span>
                                @elseif ($phase === 'current')
                                    <span class="size-6 shrink-0 rounded-full border-2 border-brand bg-surface" aria-hidden="true"></span>
                                @else
                                    <span class="size-6 shrink-0 rounded-full bg-surface-raised" aria-hidden="true"></span>
                                @endif
                                <span class="flex flex-col">
                                    <span class="font-semibold {{ $phase === 'next' ? 'text-ink-muted' : '' }}">{{ $name }}<span class="sr-only">{{ $phase === 'done' ? ', done' : ', not yet' }}</span></span>
                                    @if ($detail !== '')
                                        <span class="text-small text-ink-muted">{{ $detail }}</span>
                                    @endif
                                </span>
                            </li>
                        @endforeach
                    </ol>
                </x-ui.card>

                <div class="flex flex-col gap-3">
                    <h2 class="text-label text-ink-muted">WHILE YOU WAIT</h2>
                    <ul class="flex flex-col gap-2">
                        <li class="flex min-h-12 items-center rounded-md border border-border bg-surface px-4 font-semibold">Prepare your first 3 posts</li>
                        <li class="flex min-h-12 items-center rounded-md border border-border bg-surface px-4 font-semibold">Set prices &amp; bundles</li>
                    </ul>
                </div>
                @break

            @case('fix')
                @include('creators::partials.action-needed', [
                    'kind' => $creator->fix_kind,
                    'message' => $creator->fixMessage(),
                    'actionUrl' => route($creator->fix_kind->route()),
                    'post' => false,
                ])
                <p class="text-small text-ink-muted">When it's fixed, submit again from Review &amp; submit.</p>
                @break

            @case('approved')
                <div class="flex flex-col gap-3">
                    <h1 class="text-h2">You're approved</h1>
                    <p class="text-ink-muted">You can start posting and earning.</p>
                </div>
                <x-ui.button variant="brand" href="{{ url('/studio') }}">Open Studio</x-ui.button>
                @break

            @case('id_expired')
                <div class="flex flex-col gap-3">
                    <h1 class="text-h2">Your ID has expired</h1>
                    <p class="text-ink-muted">Posting and earning are paused until you verify a valid ID.</p>
                </div>
                <x-ui.button variant="brand" :href="route('creator.kyc')">Verify a new ID</x-ui.button>
                @break

            @case('suspended')
                <div class="flex flex-col gap-3">
                    <h1 class="text-h2">Your creator account is paused</h1>
                    <p class="text-ink-muted">Posting and earning are stopped. Contact support to find out more.</p>
                </div>
                <x-ui.button href="mailto:{{ config('fanfolio.brand.support_email') }}">Contact support</x-ui.button>
                <x-ui.button variant="ghost" :href="route('account')">Back to your account</x-ui.button>
                @break

            @case('rejected')
                <div class="flex flex-col gap-3">
                    <h1 class="text-h2">We could not approve your account</h1>
                    <p class="text-ink-muted">If you think this is a mistake, contact support.</p>
                </div>
                <x-ui.button href="mailto:{{ config('fanfolio.brand.support_email') }}">Contact support</x-ui.button>
                <x-ui.button variant="ghost" :href="route('account')">Back to your account</x-ui.button>
                @break

            @default
                <div class="flex flex-col gap-3">
                    <h1 class="text-h2">Finish your application</h1>
                    <p class="text-ink-muted">A few steps are left before we can review your account.</p>
                </div>
                <x-ui.button variant="brand" :href="route('creator.review')">Continue</x-ui.button>
        @endswitch
    </div>
</x-layouts.auth>
