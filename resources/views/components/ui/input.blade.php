@props(['name', 'label', 'type' => 'text', 'hint' => null, 'value' => null])
@php
    $id = $attributes->get('id', 'field-'.$name);
    $error = $errors->first($name);
    $describedBy = trim(($hint ? $id.'-hint ' : '').($error ? $id.'-error' : ''));
    $isPassword = $type === 'password';
@endphp
<div class="flex flex-col gap-1">
    <label for="{{ $id }}" class="text-label text-ink">{{ $label }}</label>
    <div class="relative">
        <input
            id="{{ $id }}"
            name="{{ $name }}"
            type="{{ $type }}"
            @if (! $isPassword) value="{{ old($name, $value) }}" @endif
            @if ($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif
            @if ($error) aria-invalid="true" @endif
            {{ $attributes->except('id')->merge(['class' => 'min-h-11 w-full rounded-md border border-border-strong bg-surface-raised px-3 py-2 text-ink placeholder:text-ink-muted'.($isPassword ? ' pr-12' : '').($error ? ' border-danger' : '')]) }}
        >
        @if ($isPassword)
            {{-- Show or hide what is typed. Hidden until resources/js/password-toggle.js wires it up. --}}
            <button type="button" hidden data-password-toggle="{{ $id }}" aria-controls="{{ $id }}" aria-pressed="false" aria-label="Show {{ \Illuminate\Support\Str::lower($label) }}"
                class="absolute inset-y-0 right-0 flex w-11 items-center justify-center rounded-md text-ink-muted hover:text-ink">
                <svg data-icon="show" class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/>
                </svg>
                <svg data-icon="hide" class="hidden size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M3 3l18 18"/><path d="M10.6 5.1A10.5 10.5 0 0 1 12 5c6.5 0 10 7 10 7a17.6 17.6 0 0 1-3.2 4.1M6.6 6.6C3.8 8.4 2 12 2 12s3.5 7 10 7a9.8 9.8 0 0 0 5.4-1.6"/><path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"/>
                </svg>
            </button>
        @endif
    </div>
    @if ($hint)
        <p id="{{ $id }}-hint" class="text-small text-ink-muted">{{ $hint }}</p>
    @endif
    @if ($error)
        <p id="{{ $id }}-error" class="text-small text-danger" role="alert">{{ $error }}</p>
    @endif
</div>
