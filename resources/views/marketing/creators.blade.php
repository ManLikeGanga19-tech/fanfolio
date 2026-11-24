{{-- Screen: Marketing site · For creators (canvas board MarketingCreators). The worked sale is calculated
     from the locked money rules in config (100 credits = $1, 20% fee, 7-day hold), never typed in. --}}
@php
    $brand = config('fanfolio.brand.name');
    $wrap = 'mx-auto max-w-[1200px] px-5 sm:px-8';
    $eyebrow = 'text-[13px] leading-4 font-semibold tracking-[.08em] text-brand uppercase';
    $d2 = 'font-display text-[34px] leading-[42px] font-bold tracking-[-0.01em] lg:text-[44px] lg:leading-[52px]';
    $h3 = 'font-display text-xl leading-7 font-semibold';
    $lead = 'text-[19px] leading-[30px] text-ink-muted';
    $p = 'text-base leading-[26px] text-ink-muted';
    $btn = 'inline-flex h-[52px] items-center justify-center rounded-md px-6 text-base font-semibold whitespace-nowrap';
    $primary = $btn.' bg-brand text-on-brand hover:bg-brand-hover';
    $secondary = $btn.' border border-border-strong bg-surface text-ink hover:border-ink-muted';
    $ico = 'flex size-10 shrink-0 items-center justify-center rounded-[10px] bg-surface-raised text-brand';
    $sec = 'py-20 lg:py-28';
    $creatorSignup = route('signup', ['as' => 'creator']);

    // One sale of 1,000 credits, from the configured rules (integer minor units only).
    $credits = 1000;
    $perUsd = (int) config('fanfolio.money.credits_per_usd');
    $feeBps = (int) config('fanfolio.money.platform_fee_bps');
    $saleCents = intdiv($credits * 100, $perUsd);
    $feeCents = intdiv($saleCents * $feeBps, 10000);
    $usd = static fn (int $cents): string => '$'.number_format(intdiv($cents, 100)).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    $keep = 100 - intdiv($feeBps, 100);
    $holdDays = (int) config('fanfolio.money.earnings_hold_days');
@endphp
<x-layouts.marketing title="For creators" current="creators" cta="Become a creator" :cta-href="$creatorSignup">
    {{-- Hero --}}
    <section aria-label="Introduction" class="{{ $wrap }} grid items-center gap-14 pt-16 pb-[104px] lg:grid-cols-2">
        <div class="flex flex-col gap-7">
            <p class="{{ $eyebrow }}">For creators</p>
            <h1 class="font-display text-[42px] leading-[50px] font-bold tracking-[-0.02em] lg:text-[60px] lg:leading-[68px]">Turn your following into income <span class="text-brand">you control</span></h1>
            <p class="{{ $lead }}">Share exclusive content with fans who pay for it. You set the prices, you own your audience, and you keep {{ $keep }}% of everything you earn.</p>
            <div class="flex flex-wrap gap-3">
                <a href="{{ $creatorSignup }}" class="{{ $primary }}">Start your page</a>
                <a href="#payouts" class="{{ $secondary }}">See how payouts work</a>
            </div>
            <p class="text-small text-ink-muted">Free to join. You need to be 18+ with a valid government ID.</p>
        </div>
        {{-- Canvas: hero image, the carousel's "Creator at work" photo. Decorative. --}}
        <img src="{{ Vite::asset('resources/images/auth/creator-studio.webp') }}" alt=""
            class="h-[360px] w-full rounded-3xl border border-border object-cover lg:h-[520px]" decoding="async">
    </section>

    {{-- The money --}}
    <section aria-labelledby="money" class="border-y border-border bg-surface">
        <div class="{{ $wrap }} {{ $sec }} grid items-center gap-16 lg:grid-cols-2">
            <div class="flex flex-col gap-5">
                <p class="{{ $eyebrow }}">Clear, simple pricing</p>
                <h2 id="money" class="{{ $d2 }}">You keep {{ $keep }}%. Always.</h2>
                <p class="{{ $lead }}">No setup fee, no monthly fee. {{ $brand }} takes {{ intdiv($feeBps, 100) }}% of each sale to cover payments, protection and support. Here is one sale, start to finish.</p>
            </div>
            <dl class="overflow-hidden rounded-lg border border-border bg-bg" aria-label="Example of one sale">
                <div class="flex items-center justify-between gap-4 border-b border-border px-6 py-5 font-medium"><dt>Fan pays for your post</dt><dd class="font-display text-lg font-semibold">{{ number_format($credits) }} credits · {{ $usd($saleCents) }}</dd></div>
                <div class="flex items-center justify-between gap-4 border-b border-border px-6 py-5"><dt class="{{ $p }}">{{ $brand }} fee ({{ intdiv($feeBps, 100) }}%)</dt><dd class="font-display text-lg font-semibold text-ink-muted">− {{ $usd($feeCents) }}</dd></div>
                <div class="flex items-center justify-between gap-4 border-b border-border px-6 py-5"><dt class="{{ $p }}">Clears after {{ $holdDays }} days</dt><dd class="font-display text-lg font-semibold text-ink-muted">Hold</dd></div>
                <div class="flex items-center justify-between gap-4 bg-surface-raised px-6 py-5"><dt class="font-semibold">You receive</dt><dd class="font-display text-2xl font-semibold text-brand">{{ $usd($saleCents - $feeCents) }}</dd></div>
            </dl>
        </div>
    </section>

    {{-- Four steps --}}
    <section aria-labelledby="start" class="{{ $wrap }} {{ $sec }}">
        <div class="mb-14 flex max-w-[720px] flex-col gap-4">
            <p class="{{ $eyebrow }}">Getting started</p>
            <h2 id="start" class="{{ $d2 }}">Live in four steps</h2>
        </div>
        <ol class="grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ([
                ['create-account', 'Create your account', 'Pick your creator name and your page link.'],
                ['id-check', 'Verify your ID', 'A quick ID and selfie check. We never keep the images.'],
                ['set-prices', 'Set your prices', 'Monthly price, bundles and pay-per-view. Change them any time.'],
                ['post-and-earn', 'Post and earn', 'Upload, schedule and share your page link with your fans.'],
            ] as $i => [$drawing, $title, $text])
                <li class="flex flex-col gap-3.5 rounded-lg border border-border bg-surface p-7">
                    <x-ui.illustration :name="$drawing" :size="96" />
                    <span class="flex size-10 items-center justify-center rounded-full border border-brand font-display text-base font-semibold text-brand" aria-hidden="true">{{ $i + 1 }}</span>
                    <h3 class="{{ $h3 }}">{{ $title }}</h3>
                    <p class="{{ $p }}">{{ $text }}</p>
                </li>
            @endforeach
        </ol>
    </section>

    {{-- Studio tools --}}
    <section aria-labelledby="tools" class="border-y border-border bg-surface">
        <div class="{{ $wrap }} {{ $sec }}">
            <div class="mb-14 flex max-w-[720px] flex-col gap-4">
                <p class="{{ $eyebrow }}">Your studio</p>
                <h2 id="tools" class="{{ $d2 }}">Everything you need to run your page</h2>
            </div>
            <ul class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ([
                    ['Scheduled posts', 'Plan your week and let '.$brand.' publish on time.'],
                    ['Mass messages', 'Message all subscribers at once, with or without locked media.'],
                    ['Bundles and offers', 'Discounts for 3, 6 and 12 months, and limited-time promos.'],
                    ['Earnings insights', 'See what sells, who your top fans are and where income comes from.'],
                    ['Collaborations', 'Tag verified co-creators, agree on a split and get paid automatically.'],
                    ['Fan management', 'See every subscriber, reward your top fans and block anyone you choose.'],
                ] as [$title, $text])
                    <li class="flex flex-col gap-3.5 rounded-lg border border-border bg-bg p-7"><h3 class="{{ $h3 }}">{{ $title }}</h3><p class="{{ $p }}">{{ $text }}</p></li>
                @endforeach
            </ul>
        </div>
    </section>

    {{-- Payouts --}}
    <section aria-labelledby="payouts" class="{{ $wrap }} {{ $sec }} grid items-center gap-16 lg:grid-cols-2">
        <div class="flex flex-col gap-5">
            <p class="{{ $eyebrow }}">Getting paid</p>
            <h2 id="payouts" class="{{ $d2 }} scroll-mt-24">Withdraw the way that works for you</h2>
            <p class="{{ $lead }}">Earnings become available {{ $holdDays }} days after each sale. Large withdrawals get an extra security review to keep your money safe.</p>
        </div>
        <ul class="flex flex-col gap-3">
            @foreach ([['landmark', 'Bank transfer', 'To your local bank account'], ['coin', 'Crypto', 'Stablecoin to your own wallet'], ['credit-card', 'Paxum or Payoneer', 'Global e-wallets for creators']] as [$icon, $title, $text])
                <li class="flex items-center gap-4 rounded-lg border border-border bg-bg p-5"><span class="{{ $ico }}"><x-ui.icon :name="$icon" /></span><div><h3 class="{{ $h3 }}">{{ $title }}</h3><p class="text-small text-ink-muted">{{ $text }}</p></div></li>
            @endforeach
        </ul>
    </section>

    {{-- Protection. Honest wording (brief rule 16): screen capture cannot be fully blocked on the web. --}}
    <section aria-labelledby="protect" class="border-y border-border bg-surface">
        <div class="{{ $wrap }} {{ $sec }}">
            <div class="mb-14 flex max-w-[720px] flex-col gap-4">
                <p class="{{ $eyebrow }}">Protection</p>
                <h2 id="protect" class="{{ $d2 }}">Your content, guarded</h2>
                <p class="{{ $lead }}">No website can stop every screen capture. What we do is make leaks hard, traceable and quick to remove.</p>
            </div>
            <ul class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ([
                    ['lock', 'Encrypted media', 'Videos and photos are encrypted and play only for paying fans, through short-lived links.'],
                    ['pen', 'Watermarked to the viewer', 'Each fan sees a watermark tied to their account, so a leak points back to who shared it.'],
                    ['search', 'Leak takedowns', 'Report a leak and our team files takedown requests and bans the account behind it.'],
                ] as [$icon, $title, $text])
                    <li class="flex flex-col gap-3.5 rounded-lg border border-border bg-bg p-7"><span class="{{ $ico }}"><x-ui.icon :name="$icon" /></span><h3 class="{{ $h3 }}">{{ $title }}</h3><p class="{{ $p }}">{{ $text }}</p></li>
                @endforeach
            </ul>
        </div>
    </section>

    {{-- Creator FAQ --}}
    <section aria-labelledby="faqh" id="faq" class="{{ $wrap }} {{ $sec }} grid scroll-mt-4 items-start gap-16 lg:grid-cols-2">
        <div class="flex flex-col gap-4">
            <p class="{{ $eyebrow }}">Creator FAQ</p>
            <h2 id="faqh" class="{{ $d2 }}">Before you start</h2>
        </div>
        <x-marketing.faq :items="[
            ['Do I need to show my face?', 'No. Your ID check is private and only used to confirm you are an adult. What you post is up to you.'],
            ['Can I post adult content?', 'Yes, if you are verified. Adult posts are shown only to fans who have also confirmed they are 18+.'],
            ['What if someone else appears in my post?', 'Tag them. They verify their ID and approve that post before it can go live, and they can be paid a share.'],
            ['Can I change my prices later?', 'Yes. Change your subscription price, bundles and post prices from your studio at any time.'],
        ]" />
    </section>

    {{-- Closing call to action --}}
    <section aria-label="Get started" class="{{ $wrap }} pb-20 lg:pb-28">
        <div class="flex flex-col items-center gap-5 rounded-3xl border border-border bg-surface px-6 py-12 text-center lg:p-[72px]">
            <h2 class="{{ $d2 }} max-w-[720px]">Your audience is ready. <span class="text-brand">Your page should be too.</span></h2>
            <p class="{{ $lead }} max-w-[560px]">Set up in minutes and keep {{ $keep }}% of everything you earn.</p>
            <a href="{{ $creatorSignup }}" class="{{ $primary }}">Become a creator</a>
        </div>
    </section>
</x-layouts.marketing>
