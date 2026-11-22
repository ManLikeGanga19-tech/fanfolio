<?php

declare(strict_types=1);

use App\Support\Money\Money;

it('treats one credit as one US cent', function (): void {
    $credits = Money::credits(250);

    expect($credits->amountMinor)->toBe(250)
        ->and($credits->currency)->toBe('USD')
        ->and($credits->jsonSerialize())->toBe(['amount_minor' => 250, 'currency' => 'USD']);
});

it('takes the 20% platform fee with integer maths', function (): void {
    $sale = Money::credits(250);

    expect($sale->shareBps(2000)->amountMinor)->toBe(50)
        ->and($sale->shareBps(8000)->amountMinor)->toBe(200);
});

it('allocates splits so the shares always add back to the whole', function (int $amount, array $bps): void {
    $shares = Money::credits($amount)->allocateBps($bps);
    $total = array_sum(array_map(static fn (Money $m): int => $m->amountMinor, $shares));

    expect($total)->toBe($amount);
})->with([
    'spec example: 250 credits, 20% fee, 56% owner, 24% co-performer' => [250, [5600, 2400, 2000]],
    'remainder goes to the first share' => [101, [3333, 3333, 3334]],
    'single share' => [999, [10000]],
]);

it('reproduces the spec\'s collaborative sale postings', function (): void {
    // 250-credit PPV, 20% platform fee, co-performer takes 30% of the owner's 80%.
    $sale = Money::credits(250);
    $platform = $sale->shareBps(2000);
    $creatorPool = $sale->subtract($platform);
    [$owner, $coPerformer] = $creatorPool->allocateBps([7000, 3000]);

    expect($platform->amountMinor)->toBe(50)
        ->and($owner->amountMinor)->toBe(140)
        ->and($coPerformer->amountMinor)->toBe(60);
});

it('refuses to mix currencies', function (): void {
    Money::of(100, 'USD')->add(Money::of(100, 'KES'));
})->throws(InvalidArgumentException::class);

it('refuses invalid basis points and currencies', function (callable $make): void {
    $make();
})->with([
    'bps above 100%' => [fn (): Money => Money::credits(1)->shareBps(10_001)],
    'negative bps' => [fn (): Money => Money::credits(1)->shareBps(-1)],
    'allocation not summing to 100%' => [fn (): array => Money::credits(1)->allocateBps([5000, 4000])],
    'lower-case currency' => [fn (): Money => Money::of(1, 'usd')],
])->throws(InvalidArgumentException::class);

it('compares and negates amounts', function (): void {
    $a = Money::credits(100);

    expect($a->equals(Money::credits(100)))->toBeTrue()
        ->and($a->greaterThanOrEqual(Money::credits(101)))->toBeFalse()
        ->and($a->negate()->isNegative())->toBeTrue()
        ->and(Money::zero('USD')->isZero())->toBeTrue();
});
