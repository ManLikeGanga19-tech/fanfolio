@props(['title' => null])
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ? $title.' · ' : '' }}{{ config('fanfolio.brand.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    {{ $head ?? '' }}
</head>
<body class="min-h-dvh bg-bg text-ink antialiased">
    {{-- Off-screen until focused, at full size: a 1px sr-only link would sit on the logo as a tiny touch target (WCAG 2.2 target size). --}}
    <a href="#main" class="fixed -top-full left-4 z-50 inline-flex min-h-11 items-center rounded-md bg-surface px-4 py-2 font-semibold text-ink focus:top-4">
        Skip to content
    </a>

    <header class="mx-auto flex max-w-5xl items-center justify-between px-4 py-4 md:px-8">
        <a href="{{ url('/') }}" class="font-display text-h3 font-bold text-ink">{{ config('fanfolio.brand.name') }}</a>
        {{ $actions ?? '' }}
    </header>

    <main id="main" class="mx-auto max-w-5xl px-4 pb-8 md:px-8">
        {{ $slot }}
    </main>

    <footer class="mx-auto max-w-5xl px-4 py-8 text-small text-ink-muted md:px-8">
        <nav aria-label="Legal" class="flex flex-wrap gap-4">
            <a class="underline-offset-4 hover:underline" href="{{ route('legal.terms') }}">Terms</a>
            <a class="underline-offset-4 hover:underline" href="{{ route('legal.privacy') }}">Privacy</a>
            <a class="underline-offset-4 hover:underline" href="{{ route('legal.records') }}">Records notice</a>
            <span>{{ config('fanfolio.brand.privacy_email') }}</span>
        </nav>
    </footer>
</body>
</html>
