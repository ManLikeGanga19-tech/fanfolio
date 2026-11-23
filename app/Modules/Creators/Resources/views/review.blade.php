{{-- Screen: Creator onboarding 6 · Review & submit. The co-performer statement is worded per decision D-02.
     Once submitted, a short confirmation; the full "In review" status screen (board 7) replaces it in the next PR. --}}
@php
    $idTypes = ['national_id' => 'National ID', 'passport' => 'Passport', 'driving_licence' => 'Licence'];
@endphp
<x-layouts.auth :title="$submitted ? 'Submitted for review' : 'Check everything'">
    <div class="flex flex-col gap-8">
        @if (session('status'))
            <p class="rounded-md bg-surface-raised p-4 text-small" role="status">{{ session('status') }}</p>
        @endif

        @if ($submitted)
            <div class="flex flex-col gap-3">
                <h1 class="text-h1">We're reviewing your account</h1>
                <p class="text-ink-muted">Usually under 24 hours. We'll notify you as soon as you're approved.</p>
            </div>
            <a href="{{ route('account') }}" class="inline-flex min-h-11 items-center justify-center rounded-md border border-border-strong px-4 font-semibold">Back to your account</a>
        @else
            <h1 class="text-h1">Check everything</h1>

            @php($row = 'grid grid-cols-[1fr_auto] items-center gap-x-4 gap-y-1 rounded-md bg-surface p-4')
            @php($edit = 'col-start-2 row-span-2 row-start-1')
            <dl class="flex flex-col gap-3">
                <div class="{{ $row }}">
                    <dt class="font-semibold">Page</dt>
                    <dd class="text-small text-ink-muted">{{ $creator->display_name }} · {{ '@'.$creator->handle }}</dd>
                    <dd class="{{ $edit }}"><a href="{{ route('creator.profile') }}" class="inline-flex min-h-11 items-center px-2 font-semibold text-brand underline" aria-label="Edit page">Edit</a></dd>
                </div>
                <div class="{{ $row }}">
                    <dt class="font-semibold">Identity document</dt>
                    <dd class="text-small text-ink-muted">{{ $idTypes[$kyc?->id_type] ?? 'ID document' }}@if ($kyc?->document_last4) ····{{ $kyc->document_last4 }}@endif · Captured</dd>
                </div>
                <div class="{{ $row }}">
                    <dt class="font-semibold">Selfie check</dt>
                    <dd class="text-small text-ink-muted">Done</dd>
                </div>
                <div class="{{ $row }}">
                    <dt class="font-semibold">Payouts</dt>
                    <dd class="text-small text-ink-muted">{{ $payout->display_hint }}</dd>
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
                        <label class="flex items-start gap-3 rounded-md bg-surface p-4">
                            <input type="checkbox" name="{{ $name }}" value="1" class="mt-1 size-5 shrink-0 accent-brand" @checked(old($name))
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
        @endif
    </div>
</x-layouts.auth>
