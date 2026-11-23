@props(['name', 'label', 'type' => 'text', 'hint' => null, 'value' => null])
@php
    $id = $attributes->get('id', 'field-'.$name);
    $error = $errors->first($name);
    $describedBy = trim(($hint ? $id.'-hint ' : '').($error ? $id.'-error' : ''));
@endphp
<div class="flex flex-col gap-1">
    <label for="{{ $id }}" class="text-label text-ink">{{ $label }}</label>
    <input
        id="{{ $id }}"
        name="{{ $name }}"
        type="{{ $type }}"
        @if ($type !== 'password') value="{{ old($name, $value) }}" @endif
        @if ($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif
        @if ($error) aria-invalid="true" @endif
        {{ $attributes->except('id')->merge(['class' => 'min-h-11 rounded-md border border-border-strong bg-surface-raised px-3 py-2 text-ink placeholder:text-ink-muted'.($error ? ' border-danger' : '')]) }}
    >
    @if ($hint)
        <p id="{{ $id }}-hint" class="text-small text-ink-muted">{{ $hint }}</p>
    @endif
    @if ($error)
        <p id="{{ $id }}-error" class="text-small text-danger" role="alert">{{ $error }}</p>
    @endif
</div>
