@props(['title' => null])
@php
    $slides = \App\Support\View\PhotoSlides::for('landing');
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ? $title.' · ' : '' }}{{ config('fanfolio.brand.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <link rel="preload" as="image" href="{{ Vite::asset($slides[0]) }}">
</head>
<body class="min-h-dvh bg-bg text-ink antialiased">
    <a href="#main" class="fixed -top-full left-4 z-50 inline-flex min-h-11 items-center rounded-md bg-surface px-4 py-2 font-semibold text-ink focus:top-4">
        Skip to content
    </a>

    {{-- Full-screen hero: photos cross-fade behind the words (photos from resources/images/landing). --}}
    <div class="relative isolate min-h-dvh overflow-hidden">
        <x-ui.photo-carousel folder="landing" class="absolute inset-0 -z-10" />
        {{-- Keeps the words readable: dark from the bottom on phones, from the left on large screens. --}}
        <div class="absolute inset-0 -z-10 bg-linear-to-t from-bg via-bg/75 to-bg/20 lg:bg-linear-to-r lg:from-bg lg:via-bg/70 lg:to-bg/5"></div>

        <div class="flex min-h-dvh flex-col px-6 py-6 sm:px-10 lg:px-16 xl:px-24">
            <header class="flex items-center justify-between">
                <a href="{{ url('/') }}" class="font-display text-h3 font-bold text-ink">{{ config('fanfolio.brand.name') }}</a>
                {{ $actions ?? '' }}
            </header>

            <main id="main" class="flex flex-1 flex-col justify-end py-10 lg:justify-center">
                {{ $slot }}
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
    </div>
</body>
</html>
