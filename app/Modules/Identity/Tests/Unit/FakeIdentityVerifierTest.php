<?php

declare(strict_types=1);

use App\Modules\Identity\Contracts\Data\DocumentSide;
use App\Modules\Identity\Contracts\Data\VerificationPurpose;
use App\Modules\Identity\Contracts\Data\VerificationRequest;
use App\Modules\Identity\Contracts\Data\VerificationStatus;
use App\Modules\Identity\Contracts\IdentityVerifier;
use App\Modules\Identity\Contracts\SmsSender;
use App\Modules\Identity\Gateways\Fake\FakeIdentityVerifier;
use App\Modules\Identity\Gateways\Fake\FakeSmsSender;

function startKyc(string $outcome): string
{
    /** @var FakeIdentityVerifier $kyc */
    $kyc = app(IdentityVerifier::class);

    return $kyc->willReturn($outcome)
        ->startSession(new VerificationRequest('01JSUBJECT0000000000000000', VerificationPurpose::CreatorKyc, 'https://app.test/kyc/done'))
        ->providerRef;
}

it('passes an adult by default', function (): void {
    $result = app(IdentityVerifier::class)->result(startKyc('pass'));

    expect($result->status)->toBe(VerificationStatus::Passed)
        ->and($result->isApprovedAdult())->toBeTrue();
});

it('returns fail, review, under-18 and fraud results', function (string $outcome, VerificationStatus $status, bool $adult, bool $fraud): void {
    $result = app(IdentityVerifier::class)->result(startKyc($outcome));

    expect($result->status)->toBe($status)
        ->and($result->isApprovedAdult())->toBe($adult)
        ->and($result->suspectedFraud)->toBe($fraud);
})->with([
    'fail' => ['fail', VerificationStatus::Failed, false, false],
    'review' => ['review', VerificationStatus::Review, false, false],
    'underage' => ['underage', VerificationStatus::Failed, false, false],
    'fraud' => ['fraud', VerificationStatus::Failed, false, true],
]);

it('reports an under-18 result as not over 18', function (): void {
    expect(app(IdentityVerifier::class)->result(startKyc('underage'))->ageOver18)->toBeFalse();
});

it('serves document images on demand without us storing them', function (): void {
    $image = app(IdentityVerifier::class)->fetchDocumentImage(startKyc('pass'), DocumentSide::Front);

    expect($image->contentType)->toBe('image/png')
        ->and($image->bytes)->not->toBeEmpty();
});

it('records SMS sent through the fake', function (): void {
    /** @var FakeSmsSender $sms */
    $sms = app(SmsSender::class);
    $sms->send('+254700000000', 'Your FanFolio code is 000000');

    expect($sms->lastMessageTo('+254700000000'))->toBe('Your FanFolio code is 000000');
});
