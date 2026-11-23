@props(['variant' => 'secondary', 'href' => null, 'type' => 'button'])
@php
    // Design system: brand fills only the single primary action; everything else is outlined.
    $classes = 'inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-md px-4 py-2 font-semibold transition-colors disabled:opacity-60 '
        .match ($variant) {
            'brand' => 'bg-brand text-on-brand hover:bg-brand-hover',
            'ghost' => 'text-ink hover:bg-surface-raised',
            default => 'border border-border-strong bg-surface text-ink hover:bg-surface-raised',
        };
@endphp
@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $classes]) }}>{{ $slot }}</a>
@else
    <button type="{{ $type }}" {{ $attributes->merge(['class' => $classes]) }}>{{ $slot }}</button>
@endif
