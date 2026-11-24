{{-- Screen: Marketing site · Home (canvas board MarketingHome). Mock figures on the board (studio balances,
     prices, tips) are left out: the mock-ups show their layout without invented numbers. --}}
@php
    $brand = config('fanfolio.brand.name');
    $wrap = 'mx-auto max-w-[1200px] px-5 sm:px-8';
    $eyebrow = 'text-[13px] leading-4 font-semibold tracking-[.08em] text-brand uppercase';
    $d2 = 'font-display text-[34px] leading-[42px] font-bold tracking-[-0.01em] lg:text-[44px] lg:leading-[52px]';
    $h3 = 'font-display text-xl leading-7 font-semibold';
    $lead = 'text-[19px] leading-[30px] text-ink-muted';
    $p = 'text-base leading-[26px] text-ink-muted';
    $card = 'flex flex-col gap-3.5 rounded-lg border border-border bg-surface p-7';
    $btn = 'inline-flex h-[52px] items-center justify-center gap-2 rounded-md px-6 text-base font-semibold whitespace-nowrap';
    $primary = $btn.' bg-brand text-on-brand hover:bg-brand-hover';
    $secondary = $btn.' border border-border-strong bg-surface text-ink hover:border-ink-muted';
    $ico = 'flex size-10 shrink-0 items-center justify-center rounded-[10px] bg-surface-raised text-brand';
    $sec = 'py-20 lg:py-28';
    $creatorSignup = route('signup', ['as' => 'creator']);
@endphp
<x-layouts.marketing current="fans">
    {{-- Hero --}}
    <section aria-label="Introduction" class="{{ $wrap }} grid items-center gap-12 pt-14 pb-24 lg:grid-cols-[1.1fr_.9fr]">
        <div class="flex flex-col gap-7">
            <p class="{{ $eyebrow }}">Made for African creators</p>
            <h1 class="font-display text-[44px] leading-[52px] font-bold tracking-[-0.02em] lg:text-[64px] lg:leading-[72px]">Your fans. Your content. <span class="text-brand">Your money.</span></h1>
            <p class="{{ $lead }}">{{ $brand }} is where creators across Africa share exclusive content with the fans who pay to see it. Subscriptions, unlocks, tips and paid messages, all in one private place.</p>
            <div class="flex flex-wrap gap-3">
                <a href="{{ $creatorSignup }}" class="{{ $primary }}">Start earning as a creator</a>
                <a href="{{ route('signup') }}" class="{{ $secondary }}">Find creators</a>
            </div>
            <p class="text-small text-ink-muted">Free to join. Every creator is ID-verified and 18+.</p>
        </div>

        {{-- Phone mock-up with floating notifications (decorative). --}}
        <div class="relative flex h-[560px] items-center justify-center sm:h-[640px]" aria-hidden="true">
            <div class="flex h-[620px] w-[300px] scale-90 flex-col overflow-hidden rounded-[36px] border border-border bg-bg sm:scale-100">
                <div class="h-[180px] bg-surface-raised"></div>
                <div class="-mt-9 flex flex-col gap-2.5 px-[18px]">
                    <div class="size-[72px] rounded-full border-[3px] border-bg bg-border"></div>
                    <div class="flex items-center gap-2"><span class="h-4 w-36 rounded-full bg-border-strong"></span><x-ui.icon name="check" :size="16" :stroke="2.5" class="text-brand" /></div>
                    <span class="h-3 w-24 rounded-full bg-border"></span>
                    <div class="mt-1 flex h-[46px] items-center justify-center rounded-md bg-brand text-[15px] font-semibold text-on-brand">Subscribe</div>
                    <div class="mt-1.5 grid grid-cols-3 gap-1">
                        @for ($i = 0; $i < 6; $i++)
                            <div class="h-[82px] rounded-[6px] bg-surface-raised"></div>
                        @endfor
                    </div>
                </div>
            </div>
            <div class="absolute top-[70px] left-0 flex items-center gap-3 rounded-lg border border-border bg-surface-raised px-4 py-3.5 text-sm leading-5 font-medium shadow-2xl">
                <span class="size-10 shrink-0 rounded-full bg-border"></span><span>New subscriber<br><span class="text-small text-ink-muted">just now</span></span>
            </div>
            <div class="absolute right-0 bottom-[110px] hidden items-center gap-3 rounded-lg border border-border bg-surface-raised px-4 py-3.5 text-sm leading-5 font-medium shadow-2xl sm:flex">
                <span class="flex size-9 shrink-0 items-center justify-center rounded-[10px] bg-surface-raised text-brand"><x-ui.icon name="dollar" :size="18" /></span><span>Tip received</span>
            </div>
            <div class="absolute bottom-[30px] left-5 flex items-center gap-3 rounded-lg border border-border bg-surface-raised px-4 py-3.5 text-sm leading-5 font-medium shadow-2xl">
                <span class="flex size-9 shrink-0 items-center justify-center rounded-[10px] bg-surface-raised text-brand"><x-ui.icon name="lock" :size="18" /></span><span>Post unlocked<br><span class="text-small text-ink-muted">Protected content</span></span>
            </div>
        </div>
    </section>

    {{-- Trust row --}}
    <section aria-label="Why trust {{ $brand }}" class="{{ $wrap }}">
        <ul class="grid gap-4 border-y border-border py-7 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ([['shield', 'ID-verified adult creators'], ['lock', 'Encrypted, watermarked media'], ['credit-card', 'Discreet billing name'], ['dollar', 'Payouts to bank or crypto']] as [$icon, $text])
                <li class="flex items-center gap-3 text-[15px] leading-[22px] font-medium"><span class="{{ $ico }}"><x-ui.icon :name="$icon" /></span>{{ $text }}</li>
            @endforeach
        </ul>
    </section>

    {{-- For fans --}}
    <section aria-labelledby="how" class="{{ $wrap }} {{ $sec }} scroll-mt-4" id="how-section">
        <div class="mb-14 flex max-w-[720px] flex-col gap-4">
            <p class="{{ $eyebrow }}">For fans</p>
            <h2 id="how" class="{{ $d2 }} scroll-mt-24">Get closer to the creators you love</h2>
            <p class="{{ $lead }}">No noise, no algorithm deciding what you see. Just the people you chose to support.</p>
        </div>
        <ol class="grid gap-6 lg:grid-cols-3">
            @foreach ([
                ['create-account', 'Create a free account', 'Sign up in under a minute. Creators see your username, never your real name.'],
                ['top-up-wallet', 'Top up your wallet', 'Add credits by card or crypto. One balance for every creator you follow.'],
                ['subscribe-unlock', 'Subscribe and unlock', 'Follow for free, subscribe for the full feed, unlock special posts and send messages.'],
            ] as $i => [$drawing, $title, $text])
                <li class="{{ $card }}">
                    <x-ui.illustration :name="$drawing" :size="96" />
                    <span class="flex size-10 items-center justify-center rounded-full border border-brand font-display text-base font-semibold text-brand" aria-hidden="true">{{ $i + 1 }}</span>
                    <h3 class="{{ $h3 }}">{{ $title }}</h3>
                    <p class="{{ $p }}">{{ $text }}</p>
                </li>
            @endforeach
        </ol>
    </section>

    {{-- For creators: the 80% --}}
    <section aria-labelledby="earn" class="border-y border-border bg-surface">
        <div class="{{ $wrap }} {{ $sec }} grid items-center gap-16 lg:grid-cols-2">
            <div class="flex flex-col gap-6">
                <p class="{{ $eyebrow }}">For creators</p>
                <h2 id="earn" class="{{ $d2 }}">Keep the biggest share of what you earn</h2>
                <p class="font-display text-[88px] leading-none font-bold tracking-[-0.03em] text-brand lg:text-[120px]">{{ 100 - intdiv((int) config('fanfolio.money.platform_fee_bps'), 100) }}%</p>
                <p class="{{ $lead }}">Of every subscription, unlock, tip and paid message goes to you. Earnings clear after {{ config('fanfolio.money.earnings_hold_days') }} days and you withdraw to your bank, crypto wallet, Paxum or Payoneer.</p>
                <div class="flex flex-wrap gap-3">
                    <a href="{{ $creatorSignup }}" class="{{ $primary }}">Become a creator</a>
                    <a href="{{ route('marketing.creators') }}#payouts" class="{{ $secondary }}">See how payouts work</a>
                </div>
            </div>
            {{-- Studio mock-up: layout only, no figures. --}}
            <div class="flex flex-col gap-4 rounded-lg border border-border bg-surface p-6 lg:bg-bg" aria-hidden="true">
                <p class="{{ $h3 }}">Studio</p>
                <div class="grid grid-cols-2 gap-3">
                    @foreach (['Available to withdraw', 'Active subscribers'] as $label)
                        <div class="flex flex-col gap-2 rounded-md bg-surface-raised p-4"><p class="text-small text-ink-muted">{{ $label }}</p><span class="h-7 w-24 rounded-md bg-border"></span></div>
                    @endforeach
                </div>
                <div class="flex flex-col gap-3">
                    @foreach (['Subscriptions' => 'w-3/5', 'Unlocks' => 'w-1/4', 'Tips and messages' => 'w-1/6'] as $label => $width)
                        <p class="text-small text-ink-muted">{{ $label }}</p>
                        <div class="h-2.5 overflow-hidden rounded-full bg-surface-raised"><span class="block h-full {{ $width }} rounded-full bg-brand"></span></div>
                    @endforeach
                </div>
                <div class="flex h-12 items-center justify-center rounded-md bg-brand text-[15px] font-semibold text-on-brand">Withdraw</div>
            </div>
        </div>
    </section>

    {{-- Ways to earn --}}
    <section aria-labelledby="ways" class="{{ $wrap }} {{ $sec }}">
        <div class="mb-14 flex max-w-[720px] flex-col gap-4">
            <p class="{{ $eyebrow }}">Many ways to earn</p>
            <h2 id="ways" class="{{ $d2 }}">Price your work the way you want</h2>
        </div>
        <ul class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ([
                ['Monthly subscriptions', 'Set your price and offer 3, 6 and 12-month bundles at a discount.'],
                ['Pay-per-view posts', 'Lock special photos and videos so fans unlock them one at a time.'],
                ['Tips', 'Fans say thank you with credits on any post or in chat.'],
                ['Paid messages', 'Send locked media to one fan or to all your subscribers at once.'],
                ['Collaborations', 'Post with another verified creator and split the earnings automatically.'],
                ['Free followers', 'Grow an audience with free posts, then turn followers into subscribers.'],
            ] as [$title, $text])
                <li class="{{ $card }}"><h3 class="{{ $h3 }}">{{ $title }}</h3><p class="{{ $p }}">{{ $text }}</p></li>
            @endforeach
        </ul>
    </section>

    {{-- Safety --}}
    <section aria-labelledby="safety" class="border-y border-border bg-surface">
        <div class="{{ $wrap }} {{ $sec }}">
            <div class="mb-14 flex max-w-[720px] flex-col gap-4">
                <p class="{{ $eyebrow }}">Safety first</p>
                <h2 id="safety" class="{{ $d2 }} scroll-mt-24">Built to protect creators and fans</h2>
                <p class="{{ $lead }}">We take the hard parts seriously, so you can focus on creating.</p>
            </div>
            <ul class="grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ([
                    ['shield', 'Verified adults only', 'Every creator and every person in a post passes ID and selfie checks. No one under 18, ever.'],
                    ['user-check', 'Consent on every post', 'Co-creators approve each post before it goes live, and can withdraw consent at any time.'],
                    ['lock', 'Protected content', 'Media is encrypted and watermarked to each viewer, so leaks can be traced and taken down.'],
                    ['eye', 'Your privacy', 'Fans stay anonymous to creators. Charges show a discreet name on your statement.'],
                ] as [$icon, $title, $text])
                    <li class="flex flex-col gap-3.5 rounded-lg border border-border bg-bg p-7"><span class="{{ $ico }}"><x-ui.icon :name="$icon" /></span><h3 class="{{ $h3 }}">{{ $title }}</h3><p class="{{ $p }}">{{ $text }}</p></li>
                @endforeach
            </ul>
        </div>
    </section>

    {{-- Categories --}}
    <section aria-labelledby="cats" class="{{ $wrap }} {{ $sec }}">
        <div class="mb-14 flex max-w-[720px] flex-col gap-4">
            <p class="{{ $eyebrow }}">Explore</p>
            <h2 id="cats" class="{{ $d2 }}">Something for every fan</h2>
        </div>
        {{-- Bento grid of large photo tiles (owner, 24 Nov 2026: bigger than the canvas's 220 px cards).
             Tiles rise in with Motion as they scroll into view (resources/js/reveal.js). --}}
        @php
            $categories = [
                ['name' => 'Music', 'image' => 'music', 'adult' => false, 'tile' => 'sm:col-span-2 lg:row-span-2'],
                ['name' => 'Fitness', 'image' => 'fitness', 'adult' => false, 'tile' => 'lg:col-span-2'],
                ['name' => 'Fashion and beauty', 'image' => 'fashion', 'adult' => false, 'tile' => ''],
                ['name' => 'After dark', 'image' => 'after-dark', 'adult' => true, 'tile' => 'sm:col-span-2 lg:col-span-1'],
            ];
        @endphp
        <ul class="grid auto-rows-[320px] gap-5 sm:grid-cols-2 sm:auto-rows-[300px] lg:grid-cols-4 lg:auto-rows-[290px]" data-reveal-group>
            @foreach ($categories as $category)
                <li class="{{ $category['tile'] }}" data-reveal>
                    <a href="{{ route('signup') }}" class="group relative flex size-full items-end overflow-hidden rounded-lg border border-border bg-surface-raised p-6 text-ink hover:border-border-strong lg:p-7">
                        <img src="{{ Vite::asset('resources/images/explore/'.$category['image'].'.webp') }}" alt="" loading="lazy" decoding="async"
                            class="absolute inset-0 size-full object-cover transition-transform duration-700 ease-out group-hover:scale-105 motion-reduce:transition-none motion-reduce:group-hover:scale-100">
                        <span class="absolute inset-0 bg-linear-to-t from-bg/90 via-bg/25 via-50% to-transparent" aria-hidden="true"></span>
                        <strong class="relative flex flex-wrap items-center gap-2 font-display text-2xl leading-8 font-semibold lg:text-[28px] lg:leading-9">{{ $category['name'] }}@if ($category['adult'])<span class="inline-flex h-6 items-center rounded-full border border-border bg-bg px-2.5 font-sans text-xs font-semibold text-ink-muted">18+ verified</span>@endif</strong>
                    </a>
                </li>
            @endforeach
        </ul>
    </section>

    {{-- FAQ --}}
    <section aria-labelledby="faqh" class="border-y border-border bg-surface">
        <div id="faq" class="{{ $wrap }} {{ $sec }} grid scroll-mt-4 items-start gap-16 lg:grid-cols-2">
            <div class="flex max-w-[720px] flex-col gap-4">
                <p class="{{ $eyebrow }}">FAQ</p>
                <h2 id="faqh" class="{{ $d2 }}">Questions, answered</h2>
                <p class="{{ $lead }}">Still unsure? Write to <a href="mailto:{{ config('fanfolio.brand.support_email') }}" class="text-brand underline">{{ config('fanfolio.brand.support_email') }}</a>.</p>
            </div>
            <x-marketing.faq :items="[
                ['Who can become a creator?', 'Anyone 18 or older who passes our ID and selfie check. It takes a few minutes.'],
                ['How do fans pay?', 'Fans top up a '.$brand.' wallet with credits by card or crypto, then spend credits on creators.'],
                ['When do creators get paid?', 'Earnings clear '.config('fanfolio.money.earnings_hold_days').' days after each sale. You can then withdraw to bank, crypto, Paxum or Payoneer.'],
                ['Will my bank statement say '.$brand.'?', 'No. Charges show a neutral billing name.'],
                ['Can fans get a refund?', '[CREDIT REFUND POLICY]'],
            ]" />
        </div>
    </section>

    {{-- Closing call to action --}}
    <section aria-label="Get started" class="{{ $wrap }} {{ $sec }}">
        <div class="flex flex-col items-center gap-5 rounded-3xl border border-border bg-surface px-6 py-12 text-center lg:p-[72px]">
            <p class="{{ $eyebrow }}">Join {{ $brand }}</p>
            <h2 class="{{ $d2 }} max-w-[720px]">Start building income from the people who already love your work</h2>
            <p class="{{ $lead }} max-w-[560px]">Free to join. Get verified, set your price and post your first content today.</p>
            <div class="flex flex-wrap justify-center gap-3">
                <a href="{{ $creatorSignup }}" class="{{ $primary }}">Become a creator</a>
                <a href="{{ route('signup') }}" class="{{ $secondary }}">Join as a fan</a>
            </div>
        </div>
    </section>
</x-layouts.marketing>
