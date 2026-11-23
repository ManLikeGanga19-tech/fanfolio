<?php

declare(strict_types=1);

use App\Modules\Creators\Contracts\CreatorEligibility;
use App\Modules\Creators\Enums\CreatorStatus;
use App\Modules\Creators\Enums\KycStatus;
use App\Modules\Creators\Events\CreatorKycFailed;
use App\Modules\Creators\Events\CreatorKycPassed;
use App\Modules\Creators\Models\Creator;
use App\Modules\Creators\Models\CreatorKyc;
use App\Modules\Creators\Notifications\IdExpiringSoon;
use App\Modules\Identity\Contracts\Data\DocumentImage;
use App\Modules\Identity\Contracts\Data\DocumentSide;
use App\Modules\Identity\Contracts\Data\VerificationPurpose;
use App\Modules\Identity\Contracts\Data\VerificationRequest;
use App\Modules\Identity\Contracts\Data\VerificationResult;
use App\Modules\Identity\Contracts\Data\VerificationSession;
use App\Modules\Identity\Contracts\Data\VerificationStatus;
use App\Modules\Identity\Contracts\IdentityVerifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\RecordingAuditLog;

// Creator onboarding boards 3–4 (ID and selfie) and 8 (Action needed), and ID expiry.
// The Fake KYC driver is scripted through config only: Identity's internals stay private.

beforeEach(function (): void {
    $this->audit = RecordingAuditLog::swap();
});

/** The Fake driver's outcome for the next checks: pass, fail, review, underage or fraud. */
function kycOutcome(string $outcome): void
{
    config(['fanfolio.fake.kyc_outcome' => $outcome]);
}

/** A signed-in applicant with MFA and a saved profile. */
function applicant(): Creator
{
    $user = signedInFanWithMfa();

    return Creator::factory()->create(['user_id' => $user->id]);
}

/**
 * Starts a check and follows the fake provider straight back to the return URL.
 */
function runKyc(string $outcome = 'pass'): TestResponse
{
    kycOutcome($outcome);

    $start = test()->post('/creator/kyc');
    $location = (string) $start->headers->get('Location');
    expect($location)->toStartWith(route('creator.kyc.return'));

    return test()->get($location);
}

it('sends guests to log in and non-applicants to Become a creator', function (): void {
    $this->get('/creator/kyc')->assertRedirect(route('login'));

    signedInFanWithMfa();
    $this->get('/creator/kyc')->assertRedirect(route('creator.start'));
    $this->post('/creator/kyc')->assertRedirect(route('creator.start'));
});

it('needs the page set up first', function (): void {
    $user = signedInFanWithMfa();
    Creator::factory()->create(['user_id' => $user->id, 'handle' => null, 'display_name' => null]);

    $this->get('/creator/kyc')->assertRedirect(route('creator.profile'));
    $this->post('/creator/kyc')->assertRedirect(route('creator.profile'))->assertSessionHasErrors(['profile' => 'Set up your page first.']);
});

it('goes from profile basics to the ID step', function (): void {
    signedInFanWithMfa();
    $this->post('/creator/start');

    $this->post('/creator/profile', ['display_name' => 'Test Creator', 'handle' => 'test.creator', 'category' => 'music'])
        ->assertRedirect(route('creator.kyc'));

    $this->get('/creator/kyc')
        ->assertOk()
        ->assertSee('STEP 2 OF 4 · IDENTITY DOCUMENT')
        ->assertSee("STEP 3 OF 4 · PROVE IT'S YOU", false)
        ->assertSee('We match your face to your ID, then delete the video. Fans never see it.')
        ->assertSee('Start ID check');
});

it('sends the creator to the provider and keeps only the reference', function (): void {
    $creator = applicant();

    $start = $this->post('/creator/kyc');

    $check = CreatorKyc::query()->sole();
    expect($start->headers->get('Location'))->toContain('ref='.$check->provider_ref)
        ->and($check->status)->toBe(VerificationStatus::Pending)
        ->and($creator->refresh()->kyc_status)->toBe(KycStatus::Pending)
        ->and($this->audit->of('creator.kyc_started'))->toHaveCount(1);
});

it('passes the ID and selfie to manual review, with an event after commit', function (): void {
    Event::fake([CreatorKycPassed::class]);
    $creator = applicant();

    runKyc('pass')
        ->assertRedirect(route('creator.kyc'))
        ->assertSessionHas('status', 'ID and selfie matched. Next: where should we send your money?');

    $check = CreatorKyc::query()->sole();
    expect($creator->refresh()->kyc_status)->toBe(KycStatus::Review)
        ->and($creator->id_expires_at)->not->toBeNull()
        ->and($check->status)->toBe(VerificationStatus::Passed)
        ->and($check->liveness_passed)->toBeTrue()
        ->and($check->age_over_18)->toBeTrue()
        ->and($check->document_last4)->toBe('0000')
        ->and($check->legal_name)->toBe('TEST PERSON')
        ->and($check->checked_at)->not->toBeNull()
        ->and($this->audit->of('creator.kyc_review'))->toHaveCount(1);

    Event::assertDispatchedTimes(CreatorKycPassed::class, 1);

    $this->get('/creator/kyc')->assertOk()->assertSee('ID and selfie matched')->assertSee('····0000', false)->assertDontSee('Start ID check');

    // Passing KYC alone never lets anyone publish or earn: staff approve after submit.
    $eligibility = app(CreatorEligibility::class);
    expect($eligibility->canPublish($creator->user_id))->toBeFalse()
        ->and($eligibility->canEarn($creator->user_id))->toBeFalse();
});

it('encrypts the legal name and date of birth in the database (rule 11)', function (): void {
    applicant();
    runKyc('pass');

    $row = DB::table('creator_kyc')->sole();
    expect($row->legal_name)->not->toContain('TEST PERSON')
        ->and($row->dob)->not->toMatch('/^\d{4}-\d{2}-\d{2}$/')
        ->and(CreatorKyc::query()->sole()->dob)->toMatch('/^\d{4}-\d{2}-\d{2}$/');
});

it('keeps no image, selfie or full document number column (rule 10)', function (): void {
    foreach (Schema::getColumnListing('creator_kyc') as $column) {
        expect($column)->not->toMatch('/image|photo|selfie|scan|video|blob|document_number|number/');
    }

    expect(Schema::getColumnType('creator_kyc', 'document_last4'))->toContain('char');
});

it('rejects an under-18 applicant, audited, with an event for Trust & Safety', function (): void {
    Event::fake([CreatorKycFailed::class]);
    $creator = applicant();

    runKyc('underage')->assertSessionHas('status', 'We could not verify you.');

    expect($creator->refresh()->kyc_status)->toBe(KycStatus::Rejected)
        ->and(CreatorKyc::query()->sole()->failure_reason)->toBe('under_18')
        ->and(CreatorKyc::query()->sole()->age_over_18)->toBeFalse()
        ->and($this->audit->of('creator.kyc_rejected')[0]['context']['reason'])->toBe('under_18');

    Event::assertDispatched(CreatorKycFailed::class, fn (CreatorKycFailed $e): bool => $e->creatorId === $creator->id && $e->reason === 'under_18');

    // No second try.
    $this->get('/creator/kyc')->assertSee('We could not verify you')->assertDontSee('Start ID check');
    $this->post('/creator/kyc')->assertSessionHasErrors(['kyc' => 'We could not verify you. Contact support if you think this is a mistake.']);
    expect(CreatorKyc::query()->count())->toBe(1);
});

it('rejects suspected fraud', function (): void {
    Event::fake([CreatorKycFailed::class]);
    $creator = applicant();

    runKyc('fraud');

    expect($creator->refresh()->kyc_status)->toBe(KycStatus::Rejected)
        ->and(CreatorKyc::query()->sole()->suspected_fraud)->toBeTrue();
    Event::assertDispatched(CreatorKycFailed::class, fn (CreatorKycFailed $e): bool => $e->reason === 'suspected_fraud');
});

it('rejects a date of birth under 18 even when the provider says adult', function (): void {
    Event::fake([CreatorKycFailed::class]);
    $creator = applicant();

    $verifier = new class implements IdentityVerifier
    {
        public function startSession(VerificationRequest $request): VerificationSession
        {
            $ref = 'test_kyc_'.Str::ulid();

            return new VerificationSession($ref, $request->returnUrl.'?ref='.$ref);
        }

        public function result(string $providerRef): VerificationResult
        {
            $now = new DateTimeImmutable;

            return new VerificationResult($providerRef, VerificationStatus::Passed, true, true, $now, $now->modify('-17 years'), 'TEST PERSON', 'passport', '1234', $now->modify('+5 years'));
        }

        public function fetchDocumentImage(string $providerRef, DocumentSide $side): DocumentImage
        {
            throw new RuntimeException('Not used.');
        }
    };
    app()->instance(IdentityVerifier::class, $verifier);

    $location = (string) $this->post('/creator/kyc')->headers->get('Location');
    $this->get($location);

    expect($creator->refresh()->kyc_status)->toBe(KycStatus::Rejected)
        ->and(CreatorKyc::query()->sole()->failure_reason)->toBe('under_18');
});

it('asks for a retake when the photo is unreadable (Action needed)', function (): void {
    $creator = applicant();

    runKyc('fail')->assertSessionHas('status', 'One thing to fix before we can continue.');

    expect($creator->refresh()->kyc_status)->toBe(KycStatus::ActionNeeded)
        ->and($this->audit->of('creator.kyc_action_needed'))->toHaveCount(1);

    $this->get('/creator/kyc')
        ->assertOk()
        ->assertSee('One thing to fix')
        ->assertSee('Your ID photo was too blurry to read.')
        ->assertSee('Retake ID photo');

    runKyc('pass');

    expect($creator->refresh()->kyc_status)->toBe(KycStatus::Review)
        ->and(CreatorKyc::query()->count())->toBe(2);
});

it('never takes a result from the browser, and refuses someone else\'s reference', function (): void {
    $other = Creator::factory()->create();
    kycOutcome('pass');
    $otherRef = app(IdentityVerifier::class)->startSession(new VerificationRequest($other->id, VerificationPurpose::CreatorKyc, 'http://localhost/x'))->providerRef;
    CreatorKyc::query()->create(['creator_id' => $other->id, 'provider_ref' => $otherRef, 'status' => VerificationStatus::Pending]);

    $creator = applicant();

    $this->get('/creator/kyc/return?ref='.$otherRef.'&status=passed&age_over_18=1')
        ->assertRedirect(route('creator.kyc'))
        ->assertSessionHasErrors(['kyc' => 'We could not find that check. Start again.']);

    $this->get('/creator/kyc/return?status=passed')->assertSessionHasErrors(['kyc']);

    expect($creator->refresh()->kyc_status)->toBe(KycStatus::NotStarted)
        ->and(CreatorKyc::query()->where('provider_ref', $otherRef)->sole()->status)->toBe(VerificationStatus::Pending);
});

it('ignores a reload of the return page and a stale reference', function (): void {
    Event::fake([CreatorKycPassed::class]);
    $creator = applicant();

    kycOutcome('fail');
    $first = (string) $this->post('/creator/kyc')->headers->get('Location');
    kycOutcome('pass');
    $second = (string) $this->post('/creator/kyc')->headers->get('Location');

    // The older check is replaced by the newer one and changes nothing.
    $this->get($first);
    expect($creator->refresh()->kyc_status)->toBe(KycStatus::Pending);

    $this->get($second);
    $this->get($second);

    expect($creator->refresh()->kyc_status)->toBe(KycStatus::Review)
        ->and($this->audit->of('creator.kyc_review'))->toHaveCount(1);
    Event::assertDispatchedTimes(CreatorKycPassed::class, 1);
});

it('offers to check again while the provider is still working', function (): void {
    $creator = applicant();
    $this->post('/creator/kyc');

    $ref = CreatorKyc::query()->sole()->provider_ref;
    $this->get('/creator/kyc')->assertSee('Check again')->assertSee(e(route('creator.kyc.return', ['ref' => $ref])), false);
    expect($creator->refresh()->kyc_status)->toBe(KycStatus::Pending);
});

it('stops publishing and earning once the ID expires', function (): void {
    $creator = Creator::factory()->approved()->create();
    $creator->forceFill(['id_expires_at' => now()->addDay()])->save();
    $eligibility = app(CreatorEligibility::class);

    expect($eligibility->canPublish($creator->user_id))->toBeTrue();

    $this->travel(2)->days();

    expect($eligibility->canPublish($creator->user_id))->toBeFalse()
        ->and($eligibility->canEarn($creator->user_id))->toBeFalse();
});

it('reminds an active creator once, 30 days before the ID expires', function (): void {
    Notification::fake();
    $user = signedInFan();
    $soon = Creator::factory()->approved()->create(['user_id' => $user->id]);
    $soon->forceFill(['id_expires_at' => now()->addDays(29)])->save();
    $later = Creator::factory()->approved()->create();
    $later->forceFill(['id_expires_at' => now()->addDays(90)])->save();

    $this->artisan('creators:id-expiry-reminders')->assertSuccessful();
    $this->artisan('creators:id-expiry-reminders')->assertSuccessful();

    Notification::assertSentToTimes($user, IdExpiringSoon::class, 1);
    Notification::assertCount(1);
    expect($soon->refresh()->id_expiry_reminded_at)->not->toBeNull();
});

it('lets an approved creator renew an expiring ID and stay approved when it is the same person', function (): void {
    $creator = applicant();
    runKyc('pass');
    $creator->refresh()->forceFill(['status' => CreatorStatus::Active, 'kyc_status' => KycStatus::Approved, 'approved_at' => now()])->save();

    // Not yet: the ID is valid for years.
    $this->post('/creator/kyc')->assertSessionHasErrors(['kyc' => 'Your ID and selfie are already checked.']);

    $creator->forceFill(['id_expires_at' => now()->addDays(10), 'id_expiry_reminded_at' => now()])->save();
    runKyc('pass')->assertSessionHas('status', 'Your new ID is verified.');

    $creator->refresh();
    expect($creator->kyc_status)->toBe(KycStatus::Approved)
        ->and($creator->id_expires_at?->isAfter(now()->addYears(4)))->toBeTrue()
        ->and($creator->id_expiry_reminded_at)->toBeNull()
        ->and($this->audit->of('creator.kyc_renewed'))->toHaveCount(1)
        ->and(app(CreatorEligibility::class)->canPublish($creator->user_id))->toBeTrue();
});

it('keeps a renewing creator approved when the new photo needs a retake', function (): void {
    $creator = applicant();
    runKyc('pass');
    $creator->refresh()->forceFill(['status' => CreatorStatus::Active, 'kyc_status' => KycStatus::Approved, 'approved_at' => now(), 'id_expires_at' => now()->addDays(10)])->save();

    runKyc('fail');

    expect($creator->refresh()->kyc_status)->toBe(KycStatus::Approved);
    $this->get('/creator/kyc')->assertSee('One thing to fix')->assertSee('Retake ID photo');
});

it('rate limits starting checks', function (): void {
    cache()->flush();
    applicant();

    foreach (range(1, 10) as $ignored) {
        $this->post('/creator/kyc')->assertRedirect();
    }

    $this->post('/creator/kyc')->assertTooManyRequests();
});
