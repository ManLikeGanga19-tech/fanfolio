<?php

declare(strict_types=1);

use App\Modules\Media\Contracts\AbuseHashMatcher;
use App\Modules\Media\Contracts\ContentClassifier;
use App\Modules\Media\Contracts\Data\Classification;
use App\Modules\Media\Contracts\Data\ClassifierVerdict;
use App\Modules\Media\Contracts\Data\DrmSystem;
use App\Modules\Media\Contracts\Data\LicenceRequest;
use App\Modules\Media\Contracts\DrmLicenseService;
use App\Modules\Media\Contracts\MalwareScanner;
use App\Modules\Media\Gateways\Fake\FakeAbuseHashMatcher;
use App\Modules\Media\Gateways\Fake\FakeContentClassifier;

it('issues a clear-key licence that matches the content key', function (): void {
    $drm = app(DrmLicenseService::class);
    $key = $drm->createContentKey('01JASSET000000000000000000');

    $licence = $drm->issueLicence(new LicenceRequest(DrmSystem::ClearKey, [$key->keyId], '{}', '01JSESSION0000000000000000', 60, false));
    $body = json_decode($licence->body, true, 4, JSON_THROW_ON_ERROR);
    $k = $body['keys'][0]['k'];

    expect($licence->contentType)->toBe('application/json')
        ->and($body['type'])->toBe('temporary')
        ->and(bin2hex(base64_decode(strtr($k, '-_', '+/'), true) ?: ''))->toBe($key->keyHex);
});

it('refuses real DRM systems in the fake', function (DrmSystem $system): void {
    app(DrmLicenseService::class)->issueLicence(new LicenceRequest($system, ['00000000000000000000000000000000'], 'challenge', '01JSESSION0000000000000000', 60, true));
})->with([DrmSystem::Widevine, DrmSystem::FairPlay, DrmSystem::PlayReady])->throws(RuntimeException::class);

it('refuses clear-key outside local and testing', function (): void {
    app()->detectEnvironment(fn (): string => 'staging');
    app()->forgetInstance(DrmLicenseService::class);

    app(DrmLicenseService::class);
})->throws(RuntimeException::class, 'only in local and testing');

it('flags the EICAR test object as malware', function (): void {
    expect(app(MalwareScanner::class)->scan('ingest/eicar-test.mp4')->clean)->toBeFalse()
        ->and(app(MalwareScanner::class)->scan('ingest/clip.mp4')->clean)->toBeTrue();
});

it('matches only known test hashes', function (): void {
    /** @var FakeAbuseHashMatcher $matcher */
    $matcher = app(AbuseHashMatcher::class);
    $matcher->addKnownHash(str_repeat('ab', 32));

    expect($matcher->match(str_repeat('AB', 32), 'p0')->matched)->toBeTrue()
        ->and($matcher->match(str_repeat('cd', 32), 'p1')->matched)->toBeFalse();
});

it('allows by default and returns scripted classifications', function (): void {
    /** @var FakeContentClassifier $classifier */
    $classifier = app(ContentClassifier::class);
    $classifier->willReturn('ingest/flagged.mp4', new Classification(ClassifierVerdict::Review, 0, 9400));

    expect($classifier->classify('ingest/any.mp4')->verdict)->toBe(ClassifierVerdict::Allow)
        ->and($classifier->classify('ingest/flagged.mp4')->nudityBps)->toBe(9400);
});
