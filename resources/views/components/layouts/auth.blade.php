{{-- Layout of the auth-style pages (canvas boards AuthLogin, AuthSignup): the photo carousel on the left
     (1.15 : 1), the form on the right. $slide: the slide the page opens on. $banner: below 1024 px, show the
     carousel as a 200 px banner above the form (log in and sign up only; the onboarding boards have none). --}}
@props(['title' => null, 'slide' => 0, 'banner' => false])
@php
    $slides = \App\Support\View\AuthSlides::all();
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <x-ui.timezone-meta />
    <title>{{ $title ? $title.' · ' : '' }}{{ config('fanfolio.brand.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <link rel="preload" as="image" href="{{ Vite::asset($slides[$slide]['image'] ?? $slides[0]['image']) }}" @unless ($banner) media="(min-width: 64rem)" @endunless>
    {{ $head ?? '' }}
</head>
<body class="min-h-dvh bg-bg text-ink antialiased">
    {{-- Off-screen until focused, at full size: a 1px sr-only link would sit on the logo as a tiny touch target (WCAG 2.2 target size). --}}
    <a href="#main" class="fixed -top-full left-4 z-50 inline-flex min-h-11 items-center rounded-md bg-surface px-4 py-2 font-semibold text-ink focus:top-4">
        Skip to content
    </a>

    <div class="grid min-h-dvh lg:grid-cols-[1.15fr_1fr]">
        {{-- Carousel: pinned on large screens while the form scrolls. --}}
        <x-ui.auth-carousel :start="$slide"
            class="{{ $banner ? 'h-[200px]' : 'hidden' }} lg:sticky lg:top-4 lg:block lg:h-[calc(100dvh-2rem)]" />

        <div class="flex min-h-dvh flex-col px-5 py-6 sm:px-10 lg:px-12">
            <header class="flex min-h-11 items-center justify-between gap-4">
                {{-- On large screens the logo sits on the carousel. --}}
                <a href="{{ url('/') }}" class="flex items-center gap-2.5 font-display text-h3 font-bold text-ink lg:invisible">
                    <span class="flex size-8 items-center justify-center rounded-[10px] bg-brand font-display text-base font-bold text-on-brand" aria-hidden="true">F</span>{{ config('fanfolio.brand.name') }}
                </a>
                {{ $actions ?? '' }}
            </header>

            <main id="main" class="flex flex-1 flex-col justify-center py-10">
                <div class="mx-auto w-full max-w-[400px]">
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
    </div>
</body>
</html>
