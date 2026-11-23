{{-- Decision D-14: on a computer, the ID and face scan continue on a phone.
     $link: the single-use phone link; $continue: where "I've finished on my phone" goes;
     $restart: the form action that makes a new code, with $restartFields; $devMode: development only. --}}
@props(['link', 'continue', 'restart', 'restartFields' => [], 'devMode' => false])
@php
    $minutes = max(1, (int) ceil(($link->expiresAt - now()->getTimestamp()) / 60));
@endphp
<div class="flex flex-col gap-6">
    <div class="flex flex-col gap-2">
        <h2 class="text-h2">Scan with your phone</h2>
        <p class="text-ink-muted">The ID photo and face scan need a phone camera. Open your phone's camera and point it at this code.</p>
    </div>

    {{-- Dark modules on a light tile, from the design tokens, so any phone can read it. --}}
    <div class="mx-auto w-56 rounded-lg bg-ink p-3 text-bg [&_path]:fill-current" role="img" aria-label="QR code that opens the ID check on your phone">
        {!! \App\Support\View\QrCode::svg($link->url) !!}
    </div>

    <div class="flex flex-col gap-1 text-small text-ink-muted">
        <p>Or type this link on your phone:</p>
        <p class="break-all rounded-md bg-surface p-3 font-mono text-ink" data-handoff-link>{{ $link->url }}</p>
        <p>It works once and expires in {{ $minutes }} {{ $minutes === 1 ? 'minute' : 'minutes' }}.</p>
    </div>

    <x-ui.button variant="brand" :href="$continue">I've finished on my phone</x-ui.button>

    <form method="POST" action="{{ $restart }}">
        @csrf
        <input type="hidden" name="device" value="computer">
        @foreach ($restartFields as $field => $value)
            <input type="hidden" name="{{ $field }}" value="{{ $value }}">
        @endforeach
        <x-ui.button type="submit">Show a new code</x-ui.button>
    </form>

    @if ($devMode)
        {{-- Decision D-14: local development only, to test on a PC without a phone. --}}
        <a href="{{ $link->url }}" class="inline-flex min-h-11 items-center justify-center rounded-md border border-warning px-4 text-small font-semibold">Continue on this computer (development only)</a>
    @endif
</div>
