{{-- Screen: Creator onboarding 6 · Review & submit (canvas board Main · 6). The co-performer statement is worded per decision D-02.
     After submitting, the creator goes to board 7 (creators::status). --}}
@php
    $idTypes = ['national_id' => 'National ID', 'passport' => 'Passport', 'driving_licence' => 'Licence'];
@endphp
<x-layouts.auth title="Check everything">
    <div class="flex flex-col gap-6">
        <a href="{{ route('creator.payout') }}" class="-ml-2 flex size-11 items-center justify-center text-ink" aria-label="Back"><x-ui.icon name="arrow-left" :size="24" /></a>

        @if (session('status'))
            <p class="rounded-md bg-surface-raised p-4 text-small" role="status">{{ session('status') }}</p>
        @endif

        <h1 class="text-h2">Check everything</h1>

        @if ($creator->fix_kind)
            <p class="rounded-md border border-warning p-4 text-small" role="status">{{ $creator->fixMessage() }}</p>
        @endif

        @php($row = 'grid grid-cols-[1fr_auto] items-center gap-x-4 py-3')
        @php($edit = 'col-start-2 row-span-2 row-start-1')
        <dl class="flex flex-col divide-y divide-border rounded-lg border border-border bg-surface px-4 py-1">
            <div class="{{ $row }}">
                <dt class="text-small text-ink-muted">Page</dt>
                <dd class="font-semibold">{{ $creator->display_name }} · {{ '@'.$creator->handle }}</dd>
                <dd class="{{ $edit }}"><a href="{{ route('creator.profile') }}" class="inline-flex min-h-11 items-center px-2 font-semibold text-brand underline" aria-label="Edit page">Edit</a></dd>
            </div>
            <div class="{{ $row }}">
                <dt class="text-small text-ink-muted">Identity document</dt>
                <dd class="font-semibold">{{ $idTypes[$kyc?->id_type] ?? 'ID document' }}@if ($kyc?->document_last4) ····{{ $kyc->document_last4 }}@endif</dd>
                <dd class="{{ $edit }} text-small font-semibold text-success">Captured</dd>
            </div>
            <div class="{{ $row }}">
                <dt class="text-small text-ink-muted">Selfie check</dt>
                <dd class="font-semibold">Passed</dd>
                <dd class="{{ $edit }} text-small font-semibold text-success">Done</dd>
            </div>
            <div class="{{ $row }}">
                <dt class="text-small text-ink-muted">Payouts</dt>
                <dd class="font-semibold">{{ $payout->display_hint }}</dd>
                <dd class="{{ $edit }}"><a href="{{ route('creator.payout') }}" class="inline-flex min-h-11 items-center px-2 font-semibold text-brand underline" aria-label="Edit payouts">Edit</a></dd>
            </div>
        </dl>

        <form method="POST" action="{{ route('creator.review') }}" class="flex flex-col gap-4" novalidate>
            @csrf

            @foreach ([
                'terms' => 'I agree to the <a href="'.e(route('legal.terms')).'" class="font-semibold text-brand underline">Creator Terms</a> and the 20% platform fee.',
                'co_performers' => 'Everyone in my content is 18+ and will verify their identity and consent to each post before it goes live.',
            ] as $name => $text)
                @php($error = $errors->first($name))
                <div class="flex flex-col gap-1">
                    <label class="flex min-h-11 items-start gap-3 text-small">
                        <input type="checkbox" name="{{ $name }}" value="1" class="mt-0.5 size-5 shrink-0 accent-brand" @checked(old($name))
                            @if ($error) aria-invalid="true" aria-describedby="{{ $name }}-error" @endif>
                        <span>{!! $text !!}</span>
                    </label>
                    @if ($error)
                        <p id="{{ $name }}-error" class="text-small text-danger" role="alert">{{ $error }}</p>
                    @endif
                </div>
            @endforeach

            @error('submit')
                <p class="text-small text-danger" role="alert">{{ $message }}</p>
            @enderror

            <x-ui.button variant="brand" type="submit">Submit for review</x-ui.button>
            <p class="text-center text-small text-ink-muted">Most creators are approved within 24 hours.</p>
        </form>
    </div>
</x-layouts.auth>
