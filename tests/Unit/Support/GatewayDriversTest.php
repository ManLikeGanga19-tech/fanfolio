<?php

declare(strict_types=1);

use App\Modules\Identity\Contracts\IdentityVerifier;
use App\Modules\Identity\Contracts\SmsSender;
use App\Modules\Media\Contracts\AbuseHashMatcher;
use App\Modules\Media\Contracts\ContentClassifier;
use App\Modules\Media\Contracts\DrmLicenseService;
use App\Modules\Media\Contracts\MalwareScanner;
use App\Modules\TrustSafety\Contracts\LeakCrawler;
use App\Modules\Wallet\Contracts\Data\PayoutRail;
use App\Modules\Wallet\Contracts\FxRateProvider;
use App\Modules\Wallet\Contracts\PayoutGateways;
use App\Modules\Wallet\Contracts\TopUpGateway;
use App\Support\Gateways\GatewayDriverException;

dataset('gateways', [
    IdentityVerifier::class,
    SmsSender::class,
    DrmLicenseService::class,
    MalwareScanner::class,
    AbuseHashMatcher::class,
    ContentClassifier::class,
    LeakCrawler::class,
    TopUpGateway::class,
    FxRateProvider::class,
]);

it('resolves a fake driver for every gateway in tests', function (string $contract): void {
    expect(app($contract))->toBeInstanceOf($contract)
        ->and(class_basename(app($contract)))->toStartWith('Fake');
})->with('gateways');

it('resolves a fake driver for every payout rail', function (PayoutRail $rail): void {
    $gateway = app(PayoutGateways::class)->for($rail);

    expect($gateway->rail())->toBe($rail)
        ->and(class_basename($gateway))->toBe('FakePayoutGateway');
})->with(PayoutRail::cases());

it('fails loudly on an unknown driver name', function (): void {
    config(['fanfolio.drivers.kyc' => 'acme-kyc']);
    app()->forgetInstance(IdentityVerifier::class);

    app(IdentityVerifier::class);
})->throws(GatewayDriverException::class, 'No driver "acme-kyc"');

it('refuses fake drivers in production', function (string $contract): void {
    app()->detectEnvironment(fn (): string => 'production');
    app()->forgetInstance($contract);

    app($contract);
})->with('gateways')->throws(GatewayDriverException::class, 'cannot run in production');

it('refuses fake payout rails in production', function (): void {
    app()->detectEnvironment(fn (): string => 'production');
    app()->forgetInstance(PayoutGateways::class);

    app(PayoutGateways::class)->for(PayoutRail::Bank);
})->throws(GatewayDriverException::class, 'cannot run in production');
