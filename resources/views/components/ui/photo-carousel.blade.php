@props(['folder' => 'auth', 'eager' => true])
@php
    $slides = \App\Support\View\PhotoSlides::for($folder);
@endphp
{{-- Decorative cross-fading photos (pure CSS, see .auth-slide in app.css). --}}
<div {{ $attributes->merge(['class' => 'overflow-hidden']) }} aria-hidden="true">
    @foreach ($slides as $slide)
        <img src="{{ Vite::asset($slide) }}" alt=""
            class="auth-slide absolute inset-0 size-full object-cover"
            decoding="async" @if (! ($loop->first && $eager)) loading="lazy" @endif>
    @endforeach
</div>
