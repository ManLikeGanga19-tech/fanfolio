{{-- Screen: Creator onboarding 2 · Profile basics (step 1 of 4). --}}
@php
    $handleError = $errors->first('handle');
    $categoryError = $errors->first('category');
@endphp
<x-layouts.auth title="Set up your page">
    <x-slot:actions>
        <button type="submit" form="profile-form" name="exit" value="1" class="inline-flex min-h-11 items-center rounded-md px-3 font-semibold text-ink hover:bg-surface-raised">Save &amp; exit</button>
    </x-slot:actions>

    <div class="flex flex-col gap-8">
        <div class="flex flex-col gap-2">
            <p class="text-label text-ink-muted">STEP 1 OF 4 · YOUR PAGE</p>
            <h1 class="text-h1">Set up your page</h1>
        </div>

        @if (session('status'))
            <p class="rounded-md bg-surface-raised p-4 text-small" role="status">{{ session('status') }}</p>
        @endif

        <form id="profile-form" method="POST" action="{{ route('creator.profile') }}" class="flex flex-col gap-5" novalidate>
            @csrf

            {{-- Decision D-08: photos need malware and abuse-hash scanning (rule 5), which arrives in M4. --}}
            <div class="flex items-center gap-4">
                <span class="flex size-16 shrink-0 items-center justify-center rounded-full bg-surface-raised font-display text-h3 font-bold text-brand" aria-hidden="true">{{ $creator->initials() }}</span>
                <div class="flex flex-col gap-1">
                    <p class="text-label">Profile photo</p>
                    <p class="text-small text-ink-muted">Shown on your page and in fans' feeds. You can add a photo once uploads open.</p>
                </div>
            </div>

            <x-ui.input name="display_name" label="Display name" autocomplete="nickname" maxlength="50" :value="$creator->display_name" required />

            <div class="flex flex-col gap-1">
                <label for="field-handle" class="text-label text-ink">Handle</label>
                <div class="flex min-h-11 items-center rounded-md border border-border-strong bg-surface-raised {{ $handleError ? 'border-danger' : '' }}">
                    {{-- The domain is an open decision: placeholder until the owner sets it. --}}
                    <span class="pl-3 text-ink-muted" aria-hidden="true">[domain]/</span>
                    <input id="field-handle" name="handle" type="text" value="{{ old('handle', $creator->handle) }}"
                        autocomplete="off" autocapitalize="none" spellcheck="false" maxlength="30" required
                        data-handle-check="{{ route('creator.handle-check') }}"
                        aria-describedby="field-handle-status{{ $handleError ? ' field-handle-error' : '' }}"
                        @if ($handleError) aria-invalid="true" @endif
                        class="min-h-11 w-full rounded-md bg-transparent py-2 pr-3 text-ink">
                </div>
                <p id="field-handle-status" class="text-small text-ink-muted" aria-live="polite">Lowercase letters, numbers, dots and underscores.</p>
                @if ($handleError)
                    <p id="field-handle-error" class="text-small text-danger" role="alert">{{ $handleError }}</p>
                @endif
            </div>

            <div class="flex flex-col gap-1">
                <label for="field-category" class="text-label text-ink">Category</label>
                <select id="field-category" name="category" required
                    @if ($categoryError) aria-invalid="true" aria-describedby="field-category-error" @endif
                    class="min-h-11 rounded-md border border-border-strong bg-surface-raised px-3 py-2 text-ink {{ $categoryError ? 'border-danger' : '' }}">
                    <option value="" disabled @selected(old('category', $creator->category) === null)>Choose a category</option>
                    @foreach ($categories as $key => $name)
                        <option value="{{ $key }}" @selected(old('category', $creator->category) === $key)>{{ $name }}</option>
                    @endforeach
                </select>
                @if ($categoryError)
                    <p id="field-category-error" class="text-small text-danger" role="alert">{{ $categoryError }}</p>
                @endif
            </div>

            <label class="flex items-start gap-3 rounded-md bg-surface p-4">
                <input type="checkbox" name="is_adult" value="1" class="mt-1 size-5 accent-brand" @checked(old('is_adult', $creator->is_adult)) aria-describedby="adult-hint">
                <span class="flex flex-col gap-1">
                    <span class="font-semibold">I'll post adult content (18+)</span>
                    <span id="adult-hint" class="text-small text-ink-muted">Your page is hidden from the public site and shown only to age-verified fans.</span>
                </span>
            </label>

            <x-ui.button variant="brand" type="submit">Continue</x-ui.button>
        </form>
    </div>
</x-layouts.auth>
