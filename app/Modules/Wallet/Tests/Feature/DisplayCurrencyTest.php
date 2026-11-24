<?php

declare(strict_types=1);

use App\Modules\Wallet\Actions\SetDisplayCurrency;
use App\Modules\Wallet\Actions\WalletDisplayService;
use App\Modules\Wallet\Contracts\WalletDisplay;
use App\Modules\Wallet\Exceptions\WalletException;

// Decision D-21: credits first, with an approximate amount in USD or KSh; display only.

afterEach(fn () => expectLedgerBalanced());

beforeEach(function (): void {
    $this->fan = signedInFan();
});

it('guesses KSh for a Kenyan browser and USD for everyone else', function (?string $language, string $currency): void {
    expect(app(WalletDisplay::class)->currency($this->fan->id, $language))->toBe($currency);
})->with([
    'English, Kenya' => ['en-KE,en;q=0.9', 'KES'],
    'Swahili, Kenya' => ['sw-KE', 'KES'],
    'Swahili' => ['sw', 'KES'],
    'Swahili second' => ['en-GB, sw;q=0.8', 'KES'],
    'English, US' => ['en-US,en;q=0.9', 'USD'],
    'none' => [null, 'USD'],
]);

it('lets the fan choose in Account → Currency, and the account hub follows', function (): void {
    $this->get('/account', ['Accept-Language' => 'en-US'])->assertOk()->assertSee('0 credits')->assertSee('≈ $0.00')->assertSee('Currency');

    $this->get('/account/currency')->assertOk()->assertSee('Kenyan shilling (KSh)')->assertSee('You are always charged in US dollars.');
    $this->post('/account/currency', ['currency' => 'KES'])->assertRedirect(route('account'));

    $this->get('/account', ['Accept-Language' => 'en-US'])->assertSee('KSh')->assertSee('≈ KSh 0');
    expect(app(WalletDisplay::class)->currency($this->fan->id, 'en-US'))->toBe('KES');

    // Saving again updates the same row.
    $this->post('/account/currency', ['currency' => 'USD'])->assertRedirect(route('account'));
    expect(app(WalletDisplay::class)->currency($this->fan->id, 'sw-KE'))->toBe('USD');
});

it('refuses a currency that is not offered', function (): void {
    $this->post('/account/currency', ['currency' => 'EUR'])->assertSessionHasErrors(['currency' => 'Choose one of the currencies shown.']);

    expect(fn () => app(SetDisplayCurrency::class)->handle($this->fan->id, 'XYZ'))->toThrow(WalletException::class);
});

it('converts with integers and leaves the amount out when there is no rate', function (): void {
    $display = app(WalletDisplay::class);

    expect($display->approximate(1250, 'USD'))->toBe('≈ $12.50')
        ->and($display->approximate(1250, 'KES'))->toBe('≈ KSh 1,625')
        ->and($display->approximate(1250, 'NGN'))->toBeNull()
        ->and(WalletDisplayService::dollars(-205))->toBe('−$2.05')
        ->and(WalletDisplayService::dollars(100000))->toBe('$1,000.00');

    config(['fanfolio.display_currencies' => ['USD', 'KES', 'NGN']]);
    $this->post('/account/currency', ['currency' => 'NGN']);
    $this->get('/wallet')->assertOk()->assertDontSee('≈');

    // A third currency with a rate shows its own code.
    config(['fanfolio.fake.fx_rates' => ['USD:NGN' => 1_500_000_000]]);
    expect($display->approximate(100, 'NGN'))->toBe('≈ NGN 1,500');

    // A saved choice that is no longer offered falls back to the browser's guess.
    config(['fanfolio.display_currencies' => ['USD', 'KES']]);
    expect($display->currency($this->fan->id, 'sw-KE'))->toBe('KES');
});

it('saves a valid choice through the action', function (): void {
    app(SetDisplayCurrency::class)->handle($this->fan->id, 'KES');

    expect(app(WalletDisplay::class)->currency($this->fan->id))->toBe('KES');
});
