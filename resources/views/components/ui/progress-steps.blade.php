{{-- Canvas: the segmented step bar above "STEP n OF m · …" on the onboarding boards.
     $text replaces the label line when a screen covers more than one step (e.g. "STEPS 2–3 OF 4 · ID AND SELFIE"). --}}
@props(['total', 'current', 'label' => '', 'text' => null])
<div class="flex flex-col gap-3">
    <div class="flex gap-1.5" role="progressbar" aria-valuemin="1" aria-valuemax="{{ $total }}" aria-valuenow="{{ $current }}" aria-label="Step {{ $current }} of {{ $total }}">
        @for ($i = 1; $i <= $total; $i++)
            <span class="h-1 flex-1 rounded-full {{ $i <= $current ? 'bg-brand' : 'bg-border' }}"></span>
        @endfor
    </div>
    <p class="text-label text-ink-muted">{{ $text ?? "STEP {$current} OF {$total} · {$label}" }}</p>
</div>
