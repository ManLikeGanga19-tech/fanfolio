{{-- Canvas board Main · 8 · Action needed. Used by the status page (staff asked for a fix) and the ID page
     (the provider could not read the ID).
     $kind: App\Modules\Creators\Enums\FixKind, $message: what to fix, $actionUrl: where the fix starts,
     $post: true when starting the fix is a POST (a new ID check), false for a link. --}}
@use('App\Modules\Creators\Enums\FixKind')
@php
    $fine = array_values(array_filter([
        $kind !== FixKind::RetakeId ? 'ID and selfie check' : null,
        $kind !== FixKind::Payout ? 'Payout account' : null,
        $kind !== FixKind::Profile ? 'Page details' : null,
    ]));
@endphp
<div class="flex flex-col gap-5">
    <x-ui.illustration name="action-needed" />

    <div class="flex flex-col gap-3">
        <h1 class="text-h2">One thing to fix</h1>
        <p class="text-ink-muted">{{ $message }}</p>
    </div>

    <x-ui.card class="flex flex-col gap-3 border-warning">
        <div class="flex items-center gap-3">
            <span class="flex flex-1 flex-col">
                <span class="font-semibold">{{ $kind->label() }}</span>
                <span class="text-small text-ink-muted">Takes about 1 minute</span>
            </span>
            <span class="inline-flex h-6 items-center rounded-full bg-surface-raised px-2.5 text-label text-warning">Needed</span>
        </div>
        @if ($kind === FixKind::RetakeId)
            <p class="text-small text-ink-muted">Tip: put the card on a dark surface near a window and hold the phone steady.</p>
        @endif
    </x-ui.card>

    <x-ui.card>
        <ul class="flex flex-col gap-2.5 text-small" aria-label="Already fine">
            @foreach ($fine as $item)
                <li class="flex items-center gap-3"><x-ui.icon name="check" :size="16" :stroke="2" class="text-success" />{{ $item }}</li>
            @endforeach
        </ul>
    </x-ui.card>

    <div class="flex flex-col gap-3">
        @if ($post)
            <form method="POST" action="{{ $actionUrl }}">
                @csrf
                {{-- Decision D-14: resources/js/kyc-capture.js sets phone or computer. --}}
                <input type="hidden" name="device" value="computer" data-detect>
                <x-ui.button variant="brand" type="submit">{{ $kind->label() }}</x-ui.button>
            </form>
        @else
            <x-ui.button variant="brand" :href="$actionUrl">{{ $kind->label() }}</x-ui.button>
        @endif
        <x-ui.button href="mailto:{{ config('fanfolio.brand.support_email') }}">Contact support</x-ui.button>
    </div>
</div>
