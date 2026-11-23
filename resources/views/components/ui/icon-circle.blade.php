{{-- Canvas: an icon in a round surface-raised badge (benefit rows, security methods). --}}
@props(['name', 'size' => 40, 'tone' => 'text-brand'])
<span {{ $attributes->merge(['class' => "flex shrink-0 items-center justify-center rounded-full bg-surface-raised {$tone}"]) }}
    style="width: {{ $size }}px; height: {{ $size }}px" aria-hidden="true">
    <x-ui.icon :name="$name" :size="(int) round($size / 2)" />
</span>
