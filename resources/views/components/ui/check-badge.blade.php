{{-- Canvas: the small amber square with a tick used in "what you'll need" and statement lists. --}}
<span {{ $attributes->merge(['class' => 'flex size-5 shrink-0 items-center justify-center rounded-[5px] bg-brand text-on-brand']) }} aria-hidden="true">
    <x-ui.icon name="check" :size="14" :stroke="2.5" />
</span>
