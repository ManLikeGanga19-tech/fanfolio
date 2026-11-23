@props(['title' => null])
@php
    $slides = \App\Support\View\PhotoSlides::for('auth');
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ? $title.' · ' : '' }}{{ config('fanfolio.brand.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <link rel="preload" as="image" href="{{ Vite::asset($slides[0]) }}" media="(min-width: 64rem)">
    {{ $head ?? '' }}
</head>
<body class="min-h-dvh bg-bg text-ink antialiased">
    {{-- Off-screen until focused, at full size: a 1px sr-only link would sit on the logo as a tiny touch target (WCAG 2.2 target size). --}}
    <a href="#main" class="fixed -top-full left-4 z-50 inline-flex min-h-11 items-center rounded-md bg-surface px-4 py-2 font-semibold text-ink focus:top-4">
        Skip to content
    </a>

    <div class="grid min-h-dvh lg:grid-cols-2">
        {{-- Form side: fills the screen top to bottom on every size and scrolls on its own. --}}
        <div class="flex min-h-dvh flex-col px-6 py-6 sm:px-10 lg:px-16 xl:px-24">
            <header class="flex items-center justify-between">
                <a href="{{ url('/') }}" class="font-display text-h3 font-bold text-ink">{{ config('fanfolio.brand.name') }}</a>
                {{ $actions ?? '' }}
            </header>

            <main id="main" class="flex flex-1 flex-col justify-center py-10">
                <div class="mx-auto w-full max-w-md">
                    {{ $slot }}
                </div>
            </main>

            <footer class="text-small text-ink-muted">
                <nav aria-label="Legal" class="flex flex-wrap gap-x-4 gap-y-2">
                    <a class="underline-offset-4 hover:underline" href="{{ route('legal.terms') }}">Terms</a>
                    <a class="underline-offset-4 hover:underline" href="{{ route('legal.privacy') }}">Privacy</a>
                    <a class="underline-offset-4 hover:underline" href="{{ route('legal.records') }}">Records notice</a>
                    <span>{{ config('fanfolio.brand.privacy_email') }}</span>
                </nav>
            </footer>
        </div>

        {{-- Photo side: large screens only, pinned while the form scrolls. Decorative. --}}
        <aside class="relative hidden overflow-hidden bg-surface lg:sticky lg:top-0 lg:block lg:h-dvh" aria-hidden="true">
            <x-ui.photo-carousel folder="auth" class="absolute inset-0" />

            {{-- Blends the photos into the dark theme so the tagline stays readable. --}}
            <div class="absolute inset-0 bg-linear-to-t from-bg via-bg/20 to-bg/10"></div>
            <div class="absolute inset-y-0 left-0 w-24 bg-linear-to-r from-bg to-transparent"></div>

            <div class="absolute inset-x-0 bottom-0 flex flex-col gap-6 p-12 xl:p-16">
                <p class="max-w-md font-display text-h1 font-bold text-ink">Support the creators you love, directly.</p>
                <div class="flex gap-2">
                    @foreach ($slides as $slide)
                        <span class="auth-dot block h-2 w-2 rounded-full bg-ink-muted"></span>
                    @endforeach
                </div>
            </div>
        </aside>
    </div>
</body>
</html>
