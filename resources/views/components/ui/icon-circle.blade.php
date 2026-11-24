{{-- Canvas: an icon in a round surface-raised badge (benefit rows, security methods).
     Sizes are classes, not inline styles: the CSP (SEC-02) blocks style attributes. --}}
@props(['name', 'size' => 40, 'tone' => 'text-brand'])
@php
    [$box, $icon] = match ((int) $size) {
        56 => ['size-14', 26],
        48 => ['size-12', 24],
        default => ['size-10', 20],
    };
@endphp
<span {{ $attributes->merge(['class' => "flex {$box} shrink-0 items-center justify-center rounded-full bg-surface-raised {$tone}"]) }} aria-hidden="true">
    <x-ui.icon :name="$name" :size="$icon" />
</span>
