{{-- Layout of the marketing site (canvas boards MarketingHome, MarketingCreators): header with logo, nav and
     log in; the board's footer. $current: 'fans' or 'creators' marks the nav item; $cta: the header button. --}}
@props(['title' => null, 'current' => null, 'cta' => null, 'ctaHref' => null])
@php
    $brand = config('fanfolio.brand.name');
    $navLink = 'text-[15px] leading-5 font-medium text-ink-muted hover:text-ink aria-[current=page]:text-ink';
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <x-ui.timezone-meta />
    <title>{{ $title ? $title.' · ' : '' }}{{ $brand }}</title>
    <meta name="description" content="{{ $brand }}: African creators share exclusive content with the fans who support them.">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-dvh bg-bg text-ink antialiased">
    <a href="#main" class="fixed -top-full left-4 z-50 inline-flex min-h-11 items-center rounded-md bg-surface px-4 py-2 font-semibold text-ink focus:top-4">Skip to content</a>

    <header class="mx-auto flex h-20 max-w-[1200px] items-center justify-between gap-6 px-5 sm:px-8">
        <a href="{{ url('/') }}" class="flex items-center gap-2.5 font-display text-[22px] leading-7 font-bold text-ink">
            <span class="flex size-8 items-center justify-center rounded-[10px] bg-brand font-display text-base font-bold text-on-brand" aria-hidden="true">F</span>{{ $brand }}
        </a>
        <nav aria-label="Main" class="hidden gap-7 lg:flex">
            <a href="{{ url('/') }}#how" class="{{ $navLink }}" @if ($current === 'fans') aria-current="page" @endif>For fans</a>
            <a href="{{ route('marketing.creators') }}" class="{{ $navLink }}" @if ($current === 'creators') aria-current="page" @endif>For creators</a>
            <a href="{{ url('/') }}#safety" class="{{ $navLink }}">Safety</a>
            <a href="#faq" class="{{ $navLink }}">FAQ</a>
        </nav>
        <div class="flex items-center gap-3">
            @auth
                <a href="{{ route('account') }}" class="inline-flex min-h-11 items-center px-1 text-[15px] font-semibold text-ink">Account</a>
            @else
                <a href="{{ route('login') }}" class="inline-flex min-h-11 items-center px-1 text-[15px] font-semibold text-ink">Log in</a>
            @endauth
            <a href="{{ $ctaHref ?? route('signup') }}" class="inline-flex h-11 items-center justify-center rounded-md bg-brand px-[18px] text-[15px] font-semibold whitespace-nowrap text-on-brand hover:bg-brand-hover">{{ $cta ?? 'Get started' }}</a>
        </div>
    </header>

    <main id="main">
        {{ $slot }}
    </main>

    <footer class="mx-auto max-w-[1200px] border-t border-border px-5 sm:px-8">
        <div class="grid gap-8 py-16 sm:grid-cols-2 lg:grid-cols-[2fr_1fr_1fr_1fr]">
            <div class="flex flex-col gap-3.5">
                <a href="{{ url('/') }}" class="flex items-center gap-2.5 font-display text-[22px] leading-7 font-bold text-ink">
                    <span class="flex size-8 items-center justify-center rounded-[10px] bg-brand font-display text-base font-bold text-on-brand" aria-hidden="true">F</span>{{ $brand }}
                </a>
                <p class="max-w-80 text-small text-ink-muted">The home for African creators and the fans who back them. Strictly 18+ to view adult content.</p>
            </div>
            @foreach ([
                'Product' => [['For fans', url('/').'#how'], ['For creators', route('marketing.creators')], ['Safety', url('/').'#safety']],
                'Help' => [['FAQ', url('/').'#faq'], ['Support', 'mailto:'.config('fanfolio.brand.support_email')], ['Report content', 'mailto:'.config('fanfolio.brand.support_email')]],
                'Legal' => [['Terms', route('legal.terms')], ['Privacy', route('legal.privacy')], ['Record-keeping notice', route('legal.records')]],
            ] as $heading => $links)
                <nav aria-label="{{ $heading }}">
                    <h2 class="mb-3.5 text-sm leading-5 font-semibold text-ink">{{ $heading }}</h2>
                    @foreach ($links as [$label, $href])
                        <a href="{{ $href }}" class="block text-sm leading-[30px] text-ink-muted hover:text-ink">{{ $label }}</a>
                    @endforeach
                </nav>
            @endforeach
        </div>
        <p class="border-t border-border pt-6 pb-10 text-small text-ink-muted">© {{ $brand }} · {{ config('fanfolio.brand.privacy_email') }}</p>
    </footer>
</body>
</html>
