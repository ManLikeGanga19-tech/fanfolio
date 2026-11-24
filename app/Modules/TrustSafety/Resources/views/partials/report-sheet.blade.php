{{-- Canvas FanMore · "Report content (sheet)" (plan M8a use case 28). A bottom sheet on phones, a
     dialog on wider screens. Opened by any button with data-dialog-open="{{ $id }}" (resources/js/dialogs.js).
     Host pages (creator pages, profiles) arrive with M5. $kind: creator_page or account; $target: the handle or username; $title: "Report this creator". --}}
@php
    $id = $id ?? 'report-sheet';
    $reasons = \App\Modules\TrustSafety\Enums\ReportReason::cases();
@endphp
<dialog id="{{ $id }}" aria-labelledby="{{ $id }}-title"
    class="m-0 mt-auto w-full max-w-none rounded-t-lg border border-border bg-surface p-0 text-ink backdrop:bg-bg/80 sm:m-auto sm:max-w-[440px] sm:rounded-lg">
    <form method="POST" action="{{ route('reports.store') }}" class="flex flex-col gap-3 px-4 pt-3 pb-7 sm:px-6 sm:pt-6">
        @csrf
        <input type="hidden" name="kind" value="{{ $kind }}">
        <input type="hidden" name="target" value="{{ $target }}">
        <span class="h-1 w-9 self-center rounded-full bg-border-strong sm:hidden" aria-hidden="true"></span>
        <div class="flex items-center gap-2">
            <h2 id="{{ $id }}-title" class="flex-1 font-display text-h3">{{ $title }}</h2>
            <button type="button" data-dialog-close class="flex size-11 items-center justify-center rounded-md text-[22px] text-ink-muted hover:text-ink" aria-label="Close"><span aria-hidden="true">×</span></button>
        </div>
        <p class="text-small text-ink-muted">Reports are anonymous. Anything involving a minor is reviewed within 1 hour.</p>

        <fieldset class="flex flex-col gap-2">
            <legend class="sr-only">What is wrong?</legend>
            @foreach ($reasons as $reason)
                <label class="flex min-h-12 cursor-pointer items-center gap-3 rounded-md border border-border bg-bg px-3.5 has-checked:border-brand">
                    <input type="radio" name="reason" value="{{ $reason->value }}" required class="size-4 accent-brand" @checked(old('reason') === $reason->value)>
                    <span @class(['text-danger' => $reason->isUrgent()])>{{ $reason->label() }}</span>
                </label>
            @endforeach
        </fieldset>
        @error('reason')<p class="text-small text-danger" role="alert">{{ $message }}</p>@enderror

        <label for="{{ $id }}-details" class="text-sm font-semibold">Details (optional)</label>
        <textarea id="{{ $id }}-details" name="details" rows="3" maxlength="{{ \App\Modules\TrustSafety\Actions\FileReport::DETAILS_MAX }}"
            class="rounded-md border border-border-strong bg-surface-raised px-3 py-2 text-ink">{{ old('details') }}</textarea>
        @error('details')<p class="text-small text-danger" role="alert">{{ $message }}</p>@enderror
        @error('target')<p class="text-small text-danger" role="alert">{{ $message }}</p>@enderror

        <x-ui.button type="submit" variant="brand">Submit report</x-ui.button>
    </form>
</dialog>
