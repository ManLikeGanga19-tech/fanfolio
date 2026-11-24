{{-- Canvas boards AuthLogin / AuthSignup: the photo carousel beside the form.
     Behaviour (canvas note "Auth carousel behaviour"): auto-advance every 6 s, pause on hover and focus,
     no auto-play when the user prefers reduced motion, dots are real buttons ("Slide n of m"),
     images are decorative with the headline as real text. resources/js/auth-carousel.js runs it;
     without JavaScript the first slide stays. Below 1024 px: a 200 px banner, headline only.
     $start: the slide this page opens on. --}}
@props(['start' => 0])
@php
    $slides = \App\Support\View\AuthSlides::all();
    $start = max(0, min((int) $start, count($slides) - 1));
@endphp
<section {{ $attributes->merge(['class' => 'relative m-3 overflow-hidden rounded-3xl border border-border bg-surface-raised lg:m-4']) }}
    aria-roledescription="carousel" aria-label="Why {{ config('fanfolio.brand.name') }}" data-carousel>
    @foreach ($slides as $i => $slide)
        <img src="{{ Vite::asset($slide['image']) }}" alt="" data-slide-image
            class="absolute inset-0 size-full object-cover transition-opacity duration-700 motion-reduce:transition-none {{ $i === $start ? 'opacity-100' : 'opacity-0' }}"
            decoding="async" @if ($i !== $start) loading="lazy" @endif>
    @endforeach

    {{-- Canvas: dark shade from the left so the copy reads over the photo. --}}
    <div class="absolute inset-0 bg-linear-to-r from-bg/92 via-bg/60 via-45% to-transparent to-75%" aria-hidden="true"></div>

    <a href="{{ url('/') }}" class="absolute top-8 left-10 hidden items-center gap-2.5 font-display text-[22px] leading-7 font-bold text-ink lg:flex">
        <span class="flex size-8 items-center justify-center rounded-[10px] bg-brand font-display text-base font-bold text-on-brand" aria-hidden="true">F</span>{{ config('fanfolio.brand.name') }}
    </a>

    <div class="absolute bottom-5 left-5 flex max-w-[420px] flex-col gap-4 lg:bottom-12 lg:left-10" aria-live="off" data-slide-copy>
        @foreach ($slides as $i => $slide)
            <div data-slide-text class="flex flex-col gap-4 {{ $i === $start ? '' : 'hidden' }}" @if ($i !== $start) aria-hidden="true" @endif>
                <h2 class="font-display text-[22px] leading-7 font-bold text-ink lg:text-[36px] lg:leading-[44px]">{{ $slide['headline'] }}</h2>
                <p class="hidden text-[17px] leading-[26px] text-ink/85 lg:block">{{ $slide['line'] }}</p>
            </div>
        @endforeach

        <div class="mt-3 flex gap-2" role="group" aria-label="Choose slide">
            @foreach ($slides as $i => $slide)
                <button type="button" data-slide-dot="{{ $i }}"
                    @if ($i === $start) aria-current="true" @endif
                    class="group flex h-11 w-7 items-center">
                    <span class="sr-only">Slide {{ $i + 1 }} of {{ count($slides) }}</span><span class="relative block h-1 w-full overflow-hidden rounded-full bg-border-strong" aria-hidden="true"><span data-dot-fill class="absolute inset-0 origin-left rounded-full bg-brand {{ $i === $start ? '' : 'hidden' }}"></span></span>
                </button>
            @endforeach
        </div>
    </div>
</section>
