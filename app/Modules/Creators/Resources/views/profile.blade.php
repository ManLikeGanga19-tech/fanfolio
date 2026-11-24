{{-- Screen: Creator onboarding 2 · Profile basics, step 1 of 4 (canvas board Main · 2). --}}
@php
    $handleError = $errors->first('handle');
    $categoryError = $errors->first('category');
@endphp
<x-layouts.auth title="Set up your page">
    <x-slot:actions>
        <button type="submit" form="profile-form" name="exit" value="1" class="inline-flex min-h-11 items-center rounded-md px-3 text-small font-semibold text-brand hover:bg-surface-raised">Save &amp; exit</button>
    </x-slot:actions>

    <div class="flex flex-col gap-6">
        <a href="{{ route('creator.start') }}" class="-ml-2 flex size-11 items-center justify-center text-ink" aria-label="Back"><x-ui.icon name="arrow-left" :size="24" /></a>

        <x-ui.progress-steps :total="4" :current="1" label="YOUR PAGE" />
        <h1 class="text-h2">Set up your page</h1>

        @if (session('status'))
            <p class="rounded-md bg-surface-raised p-4 text-small" role="status">{{ session('status') }}</p>
        @endif

        <form id="profile-form" method="POST" action="{{ route('creator.profile') }}" class="flex flex-col gap-5" novalidate>
            @csrf

            {{-- Decision D-08: photos need malware and abuse-hash scanning (rule 5), which arrives in M4.
                 Until then the camera circle shows the creator's initials' place, without an upload. --}}
            <div class="flex items-center gap-4">
                <span class="flex size-20 shrink-0 items-center justify-center rounded-full border border-dashed border-border-strong bg-surface-raised text-brand" aria-hidden="true">
                    <x-ui.icon name="camera" :size="26" />
                </span>
                <div class="flex flex-col gap-1">
                    <p class="font-semibold">Profile photo</p>
                    <p class="text-small text-ink-muted">Shown on your page and in fans' feeds. You can add a photo once uploads open.</p>
                </div>
            </div>

            <x-ui.input name="display_name" label="Display name" autocomplete="nickname" maxlength="50" :value="$creator->display_name" required />

            <div class="flex flex-col gap-2">
                <label for="field-handle" class="font-semibold text-ink">Handle</label>
                <div class="flex min-h-12 items-center gap-2 rounded-md border border-border-strong bg-surface-raised px-3.5 {{ $handleError ? 'border-danger' : '' }}">
                    {{-- The domain is an open decision: placeholder until the owner sets it. --}}
                    <span class="text-ink-muted" aria-hidden="true">{{ config('fanfolio.brand.domain') }}/</span>
                    <input id="field-handle" name="handle" type="text" value="{{ old('handle', $creator->handle) }}"
                        autocomplete="off" autocapitalize="none" spellcheck="false" maxlength="30" required
                        data-handle-check="{{ route('creator.handle-check') }}"
                        aria-describedby="field-handle-status field-handle-hint{{ $handleError ? ' field-handle-error' : '' }}"
                        @if ($handleError) aria-invalid="true" @endif
                        class="min-h-11 w-full min-w-0 bg-transparent py-2 text-ink">
                    {{-- Canvas: "Available" sits inside the field, on the right. --}}
                    <span id="field-handle-status" class="shrink-0 text-small font-semibold text-ink-muted" aria-live="polite"></span>
                </div>
                <p id="field-handle-hint" class="text-small text-ink-muted">Lowercase letters, numbers, dots and underscores.</p>
                @if ($handleError)
                    <p id="field-handle-error" class="text-small text-danger" role="alert">{{ $handleError }}</p>
                @endif
            </div>

            <div class="flex flex-col gap-2">
                <label for="field-category" class="font-semibold text-ink">Category</label>
                <div class="relative">
                    <select id="field-category" name="category" required
                        @if ($categoryError) aria-invalid="true" aria-describedby="field-category-error" @endif
                        class="min-h-12 w-full appearance-none rounded-md border border-border-strong bg-surface-raised py-2 pr-10 pl-3.5 text-ink {{ $categoryError ? 'border-danger' : '' }}">
                        <option value="" disabled @selected(old('category', $creator->category) === null)>Choose a category</option>
                        @foreach ($categories as $key => $name)
                            <option value="{{ $key }}" @selected(old('category', $creator->category) === $key)>{{ $name }}</option>
                        @endforeach
                    </select>
                    <x-ui.icon name="chevron-down" :size="18" class="pointer-events-none absolute top-1/2 right-3.5 -translate-y-1/2 text-ink-muted" />
                </div>
                @if ($categoryError)
                    <p id="field-category-error" class="text-small text-danger" role="alert">{{ $categoryError }}</p>
                @endif
            </div>

            {{-- Canvas: the 18+ choice is a switch in a card. A real checkbox with role="switch" keeps it a form field. --}}
            <label class="flex cursor-pointer items-center gap-4 rounded-lg border border-border bg-surface p-4">
                <span class="flex flex-1 flex-col gap-1">
                    <span class="font-semibold">I'll post adult content (18+)</span>
                    <span id="adult-hint" class="text-small text-ink-muted">Your page is hidden from the public site and shown only to age-verified fans.</span>
                </span>
                <input type="checkbox" role="switch" name="is_adult" value="1" aria-describedby="adult-hint" @checked(old('is_adult', $creator->is_adult))
                    class="peer sr-only">
                <span class="relative flex h-6 w-11 shrink-0 items-center rounded-full bg-border-strong p-[3px] transition-colors peer-checked:bg-brand peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-focus
                    after:size-[18px] after:rounded-full after:bg-ink after:transition-transform peer-checked:after:translate-x-5" aria-hidden="true"></span>
            </label>

            <x-ui.button variant="brand" type="submit">Continue</x-ui.button>
        </form>
    </div>
</x-layouts.auth>
