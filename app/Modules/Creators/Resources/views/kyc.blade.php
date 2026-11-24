{{-- Screens: Creator onboarding 3 · Scan your ID, 4 · Selfie liveness (steps 2–3 of 4), 8 · Action needed
     (canvas board Main · 3, 4, 8). The camera screens themselves run on the KYC provider's hosted page
     (decision D-11) and on a phone (D-14): images never reach FanFolio (rule 10). --}}
@use('App\Modules\Creators\Enums\KycStatus')
@use('App\Modules\Creators\Enums\CreatorStatus')
@use('App\Modules\Creators\Enums\FixKind')
@php
    $status = $creator->kyc_status;
    $idTypes = ['national_id' => 'National ID', 'passport' => 'Passport', 'driving_licence' => 'Licence'];
    $checked = $latest !== null && in_array($latest->status->value, ['passed', 'review'], true);
@endphp
<x-layouts.auth title="Verify your identity">
    <div class="flex flex-col gap-6">
        @if (session('status'))
            <p class="rounded-md bg-surface-raised p-4 text-small" role="status">{{ session('status') }}</p>
        @endif

        @error('kyc')
            <p class="rounded-md border border-danger p-4 text-small text-danger" role="alert">{{ $message }}</p>
        @enderror

        @if ($status === KycStatus::Rejected)
            <div class="flex flex-col gap-3">
                <h1 class="text-h2">We could not verify you</h1>
                <p class="text-ink-muted">Our verification partner could not confirm your identity, so you cannot become a creator with this account. If you think this is a mistake, contact support.</p>
            </div>
            <x-ui.button href="mailto:{{ config('fanfolio.brand.support_email') }}">Contact support</x-ui.button>
            <x-ui.button variant="ghost" :href="route('account')">Back to your account</x-ui.button>
        @elseif ($fix !== null && $canStart)
            @include('creators::partials.action-needed', [
                'kind' => FixKind::RetakeId,
                'message' => $fix,
                'actionUrl' => route('creator.kyc'),
                'post' => true,
            ])
        @elseif ($checked && ! $canStart)
            <div class="flex flex-col gap-3">
                <h1 class="text-h2">ID and selfie matched</h1>
                <p class="text-ink-muted">{{ $status === KycStatus::Approved ? 'Your identity is verified.' : 'Automatic check passed. Our team reviews every creator after you submit.' }}</p>
            </div>
            <x-ui.card class="px-4 py-1">
                <dl class="flex flex-col divide-y divide-border">
                    <div class="flex items-center justify-between gap-4 py-3">
                        <dt class="font-semibold">Identity document</dt>
                        <dd class="flex items-center gap-2 text-small text-ink-muted">{{ $idTypes[$latest->id_type] ?? 'ID document' }}@if ($latest->document_last4) ····{{ $latest->document_last4 }}@endif <span class="font-semibold text-success">Captured</span></dd>
                    </div>
                    <div class="flex items-center justify-between gap-4 py-3">
                        <dt class="font-semibold">Selfie check</dt>
                        <dd class="text-small font-semibold text-success">Done</dd>
                    </div>
                    @if ($creator->id_expires_at)
                        <div class="flex items-center justify-between gap-4 py-3">
                            <dt class="font-semibold">ID expires</dt>
                            <dd class="text-right text-small text-ink-muted">{{ $creator->id_expires_at->format('M Y') }}. We'll remind you 30 days before.</dd>
                        </div>
                    @endif
                </dl>
            </x-ui.card>
            @if ($creator->status === CreatorStatus::Onboarding)
                <x-ui.button variant="brand" :href="route('creator.payout')">Continue</x-ui.button>
            @else
                <x-ui.button :href="route('account')">Back to your account</x-ui.button>
            @endif
        @elseif ($handoff)
            {{-- Decision D-14: on a computer, the capture continues on a phone. --}}
            <x-ui.progress-steps :total="4" :current="3" text="STEPS 2–3 OF 4 · ID AND SELFIE" />
            <h1 class="text-h2">Continue on your phone</h1>
            <x-ui.phone-handoff :link="$handoff" :continue="route('creator.kyc.return', ['ref' => $latest->provider_ref])" :restart="route('creator.kyc')" :dev-mode="$devMode" />
        @else
            <a href="{{ route('creator.profile') }}" class="-ml-2 flex size-11 items-center justify-center text-ink" aria-label="Back"><x-ui.icon name="arrow-left" :size="24" /></a>

            @if ($status === KycStatus::Pending && $latest?->status->value === 'pending')
                <div class="flex flex-col gap-3 rounded-md bg-surface p-4">
                    <p class="font-semibold">Not finished yet?</p>
                    <p class="text-small text-ink-muted">If you already took the photos, we may still be checking them.</p>
                    <a href="{{ route('creator.kyc.return', ['ref' => $latest->provider_ref]) }}" class="inline-flex min-h-11 items-center font-semibold text-brand underline">Check again</a>
                </div>
            @endif

            <x-ui.progress-steps :total="4" :current="2" label="IDENTITY DOCUMENT" />
            <div class="flex flex-col gap-2">
                <h1 class="text-h2">Scan your ID</h1>
                <p class="text-ink-muted">Use a national ID, passport or driving licence. Fit the front of your ID inside the frame.</p>
            </div>

            <ul class="grid grid-cols-2 gap-2 text-small" aria-label="For a clear photo">
                @foreach (['Good lighting', 'No glare', 'All 4 corners', 'Not blurry'] as $tip)
                    <li class="flex items-center gap-2 rounded-md bg-surface p-3"><x-ui.icon name="check" :size="16" :stroke="2" class="text-success" />{{ $tip }}</li>
                @endforeach
            </ul>

            <x-ui.progress-steps :total="4" :current="3" label="PROVE IT'S YOU" />
            <div class="flex flex-col gap-2">
                <h2 class="text-h3">Selfie liveness check</h2>
                <p class="text-ink-muted">You'll slowly turn your head a few times.</p>
            </div>
            <div class="flex items-start gap-3 rounded-md bg-surface p-3">
                <x-ui.icon name="shield" class="text-ink-muted" />
                <p class="text-small text-ink-muted">We match your face to your ID, then delete the video. Fans never see it.</p>
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
