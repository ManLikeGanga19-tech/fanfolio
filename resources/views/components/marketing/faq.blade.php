{{-- Canvas marketing FAQ: native <details> rows (keyboard and screen-reader friendly without script),
     "+" when closed, "−" when open. The first answer starts open, as on the boards.
     $items: list of [question, answer]. --}}
@props(['items'])
<div class="flex flex-col border-t border-border">
    @foreach ($items as $i => [$question, $answer])
        <details class="group border-b border-border py-[22px]" @if ($i === 0) open @endif>
            <summary class="flex min-h-11 cursor-pointer list-none items-center justify-between gap-4 font-display text-lg leading-[26px] font-semibold text-ink [&::-webkit-details-marker]:hidden">
                {{ $question }}
                <span class="text-brand group-open:hidden" aria-hidden="true">+</span>
                <span class="hidden text-brand group-open:inline" aria-hidden="true">−</span>
            </summary>
            <p class="mt-3 text-base leading-[26px] text-ink-muted">{{ $answer }}</p>
        </details>
    @endforeach
</div>
