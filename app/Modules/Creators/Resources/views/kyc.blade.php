{{-- Screens: Creator onboarding 3 · Scan your ID, 4 · Selfie liveness (steps 2–3 of 4), 8 · Action needed.
     The capture runs on the KYC provider's hosted page (decision D-11): images never reach FanFolio (rule 10). --}}
@use('App\Modules\Creators\Enums\KycStatus')
@use('App\Modules\Creators\Enums\CreatorStatus')
@php
    $status = $creator->kyc_status;
    $idTypes = ['national_id' => 'National ID', 'passport' => 'Passport', 'driving_licence' => 'Licence'];
    $checked = $latest !== null && in_array($latest->status->value, ['passed', 'review'], true);
@endphp
<x-layouts.auth title="Verify your identity">
    <div class="flex flex-col gap-8">
        @if (session('status'))
            <p class="rounded-md bg-surface-raised p-4 text-small" role="status">{{ session('status') }}</p>
        @endif

        @error('kyc')
            <p class="rounded-md border border-danger p-4 text-small text-danger" role="alert">{{ $message }}</p>
        @enderror

        @if ($status === KycStatus::Rejected)
            <div class="flex flex-col gap-3">
                <h1 class="text-h1">We could not verify you</h1>
                <p class="text-ink-muted">Our verification partner could not confirm your identity, so you cannot become a creator with this account. If you think this is a mistake, contact support.</p>
            </div>
            <a href="{{ route('account') }}" class="inline-flex min-h-11 items-center justify-center rounded-md border border-border-strong px-4 font-semibold">Back to your account</a>
        @elseif ($fix !== null && $canStart)
            {{-- Board 8 · Action needed. --}}
            <div class="flex flex-col gap-3">
                <h1 class="text-h1">One thing to fix</h1>
                <p class="text-ink-muted">{{ $fix }}</p>
            </div>
            <x-ui.card class="flex flex-col gap-2">
                <p class="font-semibold">Retake ID photo</p>
                <p class="text-small text-ink-muted">Takes about 1 minute</p>
                <p class="text-small">Tip: put the card on a dark surface near a window and hold the phone steady.</p>
            </x-ui.card>
            <form method="POST" action="{{ route('creator.kyc') }}">
                @csrf
                <input type="hidden" name="device" value="computer" data-detect>
                <x-ui.button variant="brand" type="submit" class="w-full">Retake ID photo</x-ui.button>
            </form>
        @elseif ($checked && ! $canStart)
            <div class="flex flex-col gap-3">
                <h1 class="text-h1">ID and selfie matched</h1>
                <p class="text-ink-muted">{{ $status === KycStatus::Approved ? 'Your identity is verified.' : 'Automatic check passed. Our team reviews every creator after you submit.' }}</p>
            </div>
            <dl class="flex flex-col gap-3">
                <div class="flex items-center justify-between gap-4 rounded-md bg-surface p-4">
                    <dt class="font-semibold">Identity document</dt>
                    <dd class="text-small text-ink-muted">{{ $idTypes[$latest->id_type] ?? 'ID document' }}@if ($latest->document_last4) ····{{ $latest->document_last4 }}@endif · Captured</dd>
                </div>
                <div class="flex items-center justify-between gap-4 rounded-md bg-surface p-4">
                    <dt class="font-semibold">Selfie check</dt>
                    <dd class="text-small text-ink-muted">Done</dd>
                </div>
                @if ($creator->id_expires_at)
                    <div class="flex items-center justify-between gap-4 rounded-md bg-surface p-4">
                        <dt class="font-semibold">ID expires</dt>
                        <dd class="text-small text-ink-muted">{{ $creator->id_expires_at->format('M Y') }}. We'll remind you 30 days before.</dd>
                    </div>
                @endif
            </dl>
            @if ($creator->status === CreatorStatus::Onboarding)
                <x-ui.button variant="brand" :href="route('creator.payout')">Continue</x-ui.button>
            @else
                <a href="{{ route('account') }}" class="inline-flex min-h-11 items-center justify-center rounded-md border border-border-strong px-4 font-semibold">Back to your account</a>
            @endif
        @elseif ($handoff)
            {{-- Decision D-14: on a computer, the capture continues on a phone. --}}
            <div class="flex flex-col gap-2">
                <p class="text-label text-ink-muted">STEPS 2–3 OF 4 · ID AND SELFIE</p>
                <h1 class="text-h1">Continue on your phone</h1>
            </div>
            <x-ui.phone-handoff :link="$handoff" :continue="route('creator.kyc.return', ['ref' => $latest->provider_ref])" :restart="route('creator.kyc')" :dev-mode="$devMode" />
        @else
            @if ($status === KycStatus::Pending && $latest?->status->value === 'pending')
                <div class="flex flex-col gap-3 rounded-md bg-surface p-4">
                    <p class="font-semibold">Not finished yet?</p>
                    <p class="text-small text-ink-muted">If you already took the photos, we may still be checking them.</p>
                    <a href="{{ route('creator.kyc.return', ['ref' => $latest->provider_ref]) }}" class="inline-flex min-h-11 items-center font-semibold text-brand underline">Check again</a>
                </div>
            @endif

            <div class="flex flex-col gap-2">
                <p class="text-label text-ink-muted">STEP 2 OF 4 · IDENTITY DOCUMENT</p>
                <h1 class="text-h1">Scan your ID</h1>
                <p class="text-ink-muted">Use a national ID, passport or driving licence. Fit the front of your ID inside the frame.</p>
            </div>

            <ul class="grid grid-cols-2 gap-2 text-small" aria-label="For a clear photo">
                <li class="rounded-md bg-surface p-3">Good lighting</li>
                <li class="rounded-md bg-surface p-3">No glare</li>
                <li class="rounded-md bg-surface p-3">All 4 corners</li>
                <li class="rounded-md bg-surface p-3">Not blurry</li>
            </ul>

            <div class="flex flex-col gap-2">
                <p class="text-label text-ink-muted">STEP 3 OF 4 · PROVE IT'S YOU</p>
                <h2 class="text-h3">Selfie liveness check</h2>
                <p class="text-ink-muted">You'll slowly turn your head a few times. We match your face to your ID, then delete the video. Fans never see it.</p>
            </div>

            <form method="POST" action="{{ route('creator.kyc') }}" class="flex flex-col gap-3">
                @csrf
                <input type="hidden" name="device" value="computer" data-detect>
                <x-ui.button variant="brand" type="submit">Start ID check</x-ui.button>
                <p class="text-small text-ink-muted">On a computer we'll show a QR code: the ID photo and face scan need a phone camera.</p>
                {{-- Decision D-01 and D-11: the provider takes and keeps the images; we never store them. --}}
                <p class="text-small text-ink-muted">You must be 18 or older. The photos are taken by our verification partner, not stored by FanFolio.</p>
            </form>
        @endif
    </div>
</x-layouts.auth>
