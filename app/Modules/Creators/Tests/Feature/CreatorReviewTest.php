<?php

declare(strict_types=1);

use App\Modules\Creators\Actions\ApproveCreator;
use App\Modules\Creators\Actions\RejectCreator;
use App\Modules\Creators\Actions\RequestCreatorFix;
use App\Modules\Creators\Actions\SuspendCreator;
use App\Modules\Creators\Contracts\CreatorEligibility;
use App\Modules\Creators\Enums\CreatorStatus;
use App\Modules\Creators\Enums\FixKind;
use App\Modules\Creators\Enums\KycStatus;
use App\Modules\Creators\Events\CreatorApproved;
use App\Modules\Creators\Events\CreatorKycFailed;
use App\Modules\Creators\Events\CreatorSuspended;
use App\Modules\Creators\Exceptions\CreatorException;
use App\Modules\Creators\Models\Creator;
use App\Modules\Creators\Notifications\ApplicationDecision;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Tests\Support\RecordingAuditLog;

// Staff decisions (decision D-10), board 7 (In review) and board 8 (Action needed).
// M2 "Done when": an unverified creator cannot publish or earn.

beforeEach(function (): void {
    $this->audit = RecordingAuditLog::swap();
    Notification::fake();
    $this->staff = signedInStaff();
});

/**
 * A creator who went through the real onboarding screens and submitted, still
 * signed in as themselves.
 */
function submittedApplicant(): Creator
{
    $user = signedInFanWithMfa();
    $creator = Creator::factory()->create(['user_id' => $user->id, 'display_name' => 'Test Creator', 'handle' => 'test.creator']);

    config(['fanfolio.fake.kyc_outcome' => 'pass']);
    test()->get((string) test()->post('/creator/kyc')->headers->get('Location'));
    confirmedSecondFactor();
    test()->post('/creator/payout', ['rail' => 'bank', 'bank_name' => 'Test Bank', 'account_number' => '000011112222', 'account_name' => 'Test Person']);
    test()->post('/creator/review', ['terms' => '1', 'co_performers' => '1'])->assertRedirect(route('creator.status'));

    expect($creator->refresh()->status)->toBe(CreatorStatus::Submitted);

    return $creator;
}

it('shows board 7 after submitting, and Review & submit sends there', function (): void {
    submittedApplicant();

    $this->get('/creator/status')
        ->assertOk()
        ->assertSee("We're reviewing your account", false)
        ->assertSee("We'll email you as soon as you're approved.", false)
        ->assertSee('ID and selfie matched')
        ->assertSee('Manual review')
        ->assertSee('Approved · start earning')
        ->assertSee('WHILE YOU WAIT');

    $this->get('/creator/review')->assertRedirect(route('creator.status'));
});

it('approves: active, audited by the staff member, event, email, and the creator can publish and earn', function (): void {
    Event::fake([CreatorApproved::class]);
    $creator = submittedApplicant();

    app(ApproveCreator::class)->handle($creator->id, $this->staff->id);

    $creator->refresh();
    expect($creator->status)->toBe(CreatorStatus::Active)
        ->and($creator->kyc_status)->toBe(KycStatus::Approved)
        ->and($creator->approved_at)->not->toBeNull()
        ->and($creator->reviewed_by)->toBe($this->staff->id)
        ->and($this->audit->of('creator.approved'))->toHaveCount(1)
        ->and($this->audit->of('creator.approved')[0]['actor'])->toBe($this->staff->id);

    Event::assertDispatchedTimes(CreatorApproved::class, 1);
    Notification::assertSentTimes(ApplicationDecision::class, 1);

    $eligibility = app(CreatorEligibility::class);
    expect($eligibility->canPublish($creator->user_id))->toBeTrue()
        ->and($eligibility->canEarn($creator->user_id))->toBeTrue();
});

it('gives the account the creator role when approved, once', function (): void {
    $creator = submittedApplicant();
    expect(roleOf($creator->user_id))->toBe('fan');

    app(ApproveCreator::class)->handle($creator->id, $this->staff->id);

    expect(roleOf($creator->user_id))->toBe('creator')
        ->and($this->audit->of('identity.role_changed'))->toHaveCount(1);

    // A replayed event changes nothing.
    CreatorApproved::dispatch($creator->id, $creator->user_id);
    expect($this->audit->of('identity.role_changed'))->toHaveCount(1);
});

it('refuses decisions from anyone but staff with MFA, and on your own application', function (): void {
    $creator = submittedApplicant();

    expect(fn () => app(ApproveCreator::class)->handle($creator->id, $creator->user_id))
        ->toThrow(CreatorException::class, 'Only staff with MFA can review creators.');

    $fan = signedInFan();
    expect(fn () => app(ApproveCreator::class)->handle($creator->id, $fan->id))
        ->toThrow(CreatorException::class, 'Only staff with MFA can review creators.');

    // A member of staff with their own creator application.
    $own = Creator::factory()->withStatus(CreatorStatus::Submitted, KycStatus::Review)->create(['user_id' => $this->staff->id]);
    expect(fn () => app(ApproveCreator::class)->handle($own->id, $this->staff->id))
        ->toThrow(CreatorException::class, 'You cannot review your own application.');

    expect($creator->refresh()->status)->toBe(CreatorStatus::Submitted)
        ->and($this->audit->of('creator.approved'))->toHaveCount(0);
});

it('approves only a submitted application with a passed ID check and a payout method', function (CreatorStatus $status, KycStatus $kyc): void {
    $user = signedInFanWithMfa();
    $creator = Creator::factory()->withStatus($status, $kyc)->create(['user_id' => $user->id]);

    expect(fn () => app(ApproveCreator::class)->handle($creator->id, $this->staff->id))->toThrow(CreatorException::class);
    expect(app(CreatorEligibility::class)->canPublish($user->id))->toBeFalse();
})->with([
    'onboarding' => [CreatorStatus::Onboarding, KycStatus::Review],
    'submitted, KYC pending' => [CreatorStatus::Submitted, KycStatus::Pending],
    'submitted, KYC rejected' => [CreatorStatus::Submitted, KycStatus::Rejected],
    'submitted, no payout method' => [CreatorStatus::Submitted, KycStatus::Review],
    'suspended' => [CreatorStatus::Suspended, KycStatus::Approved],
]);

it('rejects: final, audited with the reason, Trust & Safety told, neutral message to the creator', function (): void {
    Event::fake([CreatorKycFailed::class]);
    $creator = submittedApplicant();

    app(RejectCreator::class)->handle($creator->id, $this->staff->id, 'suspected_minor');

    $creator->refresh();
    expect($creator->kyc_status)->toBe(KycStatus::Rejected)
        ->and($creator->status)->toBe(CreatorStatus::Onboarding)
        ->and($this->audit->of('creator.rejected')[0]['context'])->toBe(['reason' => 'suspected_minor']);
    Event::assertDispatched(CreatorKycFailed::class, static fn (CreatorKycFailed $e): bool => $e->reason === 'suspected_minor');

    $this->get('/creator/status')->assertSee('We could not approve your account')->assertDontSee('minor');
    $this->post('/creator/kyc')->assertSessionHasErrors('kyc');
    $this->post('/creator/review', ['terms' => '1', 'co_performers' => '1'])->assertRedirect(route('creator.kyc'));
    expect(app(CreatorEligibility::class)->canPublish($creator->user_id))->toBeFalse();
});

it('refuses a rejection without a known reason', function (): void {
    $creator = submittedApplicant();

    expect(fn () => app(RejectCreator::class)->handle($creator->id, $this->staff->id, 'because'))->toThrow(CreatorException::class, 'Choose a reason.');
});

it('sends an application back for a new ID photo (board 8), then takes it again', function (): void {
    $creator = submittedApplicant();

    app(RequestCreatorFix::class)->handle($creator->id, $this->staff->id, FixKind::RetakeId);

    $creator->refresh();
    expect($creator->status)->toBe(CreatorStatus::Onboarding)
        ->and($creator->kyc_status)->toBe(KycStatus::ActionNeeded)
        ->and($this->audit->of('creator.fix_requested')[0]['context'])->toBe(['kind' => 'retake_id', 'with_note' => false]);
    Notification::assertSentTimes(ApplicationDecision::class, 1);

    $this->get('/creator/status')
        ->assertSee('One thing to fix')
        ->assertSee('Your ID photo was too blurry to read. Everything else is fine.')
        ->assertSee('Retake ID photo')
        ->assertSee(route('creator.kyc'));
    $this->get('/creator/kyc')->assertSee('One thing to fix')->assertSee('Retake ID photo');

    // Retake passes, submit again: the fix is cleared.
    $this->get((string) $this->post('/creator/kyc')->headers->get('Location'));
    expect($creator->refresh()->kyc_status)->toBe(KycStatus::Review);

    $this->post('/creator/review', ['terms' => '1', 'co_performers' => '1'])->assertRedirect(route('creator.status'));
    $creator->refresh();
    expect($creator->status)->toBe(CreatorStatus::Submitted)
        ->and($creator->fix_kind)->toBeNull();
});

it('sends an application back to change the payout method, with the staff note', function (): void {
    $creator = submittedApplicant();

    app(RequestCreatorFix::class)->handle($creator->id, $this->staff->id, FixKind::Payout, '  Use an account in your own name.  ');

    $creator->refresh();
    expect($creator->kyc_status)->toBe(KycStatus::Review)
        ->and($creator->fix_note)->toBe('Use an account in your own name.');

    $this->get('/creator/status')->assertSee('Use an account in your own name.')->assertSee(route('creator.payout'));
    $this->get('/creator/review')->assertOk()->assertSee('Use an account in your own name.');
});

it('keeps fix notes short', function (): void {
    $creator = submittedApplicant();

    expect(fn () => app(RequestCreatorFix::class)->handle($creator->id, $this->staff->id, FixKind::Profile, str_repeat('a', 301)))
        ->toThrow(CreatorException::class, 'Keep the note under 300 characters.');
});

it('suspends an active creator at once, with an event, once', function (): void {
    Event::fake([CreatorSuspended::class]);
    $creator = submittedApplicant();
    app(ApproveCreator::class)->handle($creator->id, $this->staff->id);

    app(SuspendCreator::class)->handle($creator->id, $this->staff->id, 'leak_report');
    app(SuspendCreator::class)->handle($creator->id, $this->staff->id, 'leak_report');

    $creator->refresh();
    expect($creator->status)->toBe(CreatorStatus::Suspended)
        ->and($creator->suspended_at)->not->toBeNull()
        ->and($this->audit->of('creator.suspended'))->toHaveCount(1)
        ->and(app(CreatorEligibility::class)->canPublish($creator->user_id))->toBeFalse()
        ->and(app(CreatorEligibility::class)->canEarn($creator->user_id))->toBeFalse();
    Event::assertDispatchedTimes(CreatorSuspended::class, 1);

    $this->get('/creator/status')->assertSee('Your creator account is paused');
});

it('suspends only active creators', function (): void {
    $creator = submittedApplicant();

    expect(fn () => app(SuspendCreator::class)->handle($creator->id, $this->staff->id, 'leak_report'))
        ->toThrow(CreatorException::class, 'Only an active creator can be suspended.');
});
