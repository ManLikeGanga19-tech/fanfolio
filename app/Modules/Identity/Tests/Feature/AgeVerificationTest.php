<?php

declare(strict_types=1);

use App\Modules\Identity\Contracts\Data\VerificationStatus;
use App\Modules\Identity\Contracts\IdentityVerifier;
use App\Modules\Identity\Events\AgeVerified;
use App\Modules\Identity\Gateways\Fake\FakeIdentityVerifier;
use App\Modules\Identity\Models\AgeVerification;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\Support\RecordingAuditLog;

function fakeVerifier(): FakeIdentityVerifier
{
    $verifier = app(IdentityVerifier::class);
    assert($verifier instanceof FakeIdentityVerifier);

    return $verifier;
}

/**
 * Starts a check and follows the fake provider straight back to the return URL.
 */
function runAgeCheck(string $method = 'document'): TestResponse
{
    $start = test()->post('/signup/age', ['method' => $method, 'device' => 'phone']);
    $location = (string) $start->headers->get('Location');

    expect($location)->toStartWith(route('signup.age.return'));

    return test()->get($location);
}

beforeEach(function (): void {
    $this->audit = RecordingAuditLog::swap();
    $this->user = User::factory()->create();
    actAs($this->user);
});

it('shows step 3 with both options and a way to skip', function (): void {
    $this->get('/signup/age')
        ->assertOk()
        ->assertSee('STEP 3 OF 3 · AGE CHECK')
        ->assertSee('Confirm you are 18+')
        ->assertSee('creators never see it and we never store the image')
        ->assertSee('Verify with ID')
        ->assertSee('Selfie age check')
        ->assertSee('Not now, show safe content only');
});

it('marks the fan 18+ when the provider passes them', function (): void {
    Event::fake([AgeVerified::class]);

    runAgeCheck()->assertRedirect(route('account'))->assertSessionHas('status', 'Your age is verified.');

    expect($this->user->refresh()->isAgeVerified())->toBeTrue()
        ->and(AgeVerification::query()->sole()->status)->toBe(VerificationStatus::Passed)
        ->and($this->audit->has('identity.age_check_passed'))->toBeTrue();
    Event::assertDispatched(AgeVerified::class, fn (AgeVerified $e): bool => $e->userId === $this->user->id);

    $this->get('/account')->assertSee('Age verified');
});

it('supports the selfie age check', function (): void {
    runAgeCheck('selfie_age')->assertRedirect(route('account'));

    expect(AgeVerification::query()->sole()->method->value)->toBe('selfie_age');
});

it('does not verify when the provider fails, finds them under 18 or suspects fraud', function (string $outcome, string $reason): void {
    fakeVerifier()->willReturn($outcome);

    runAgeCheck()->assertRedirect(route('signup.age'))->assertSessionHasErrors('method');

    $check = AgeVerification::query()->sole();
    expect($this->user->refresh()->isAgeVerified())->toBeFalse()
        ->and($check->status)->toBe(VerificationStatus::Failed)
        ->and($check->failure_reason)->toBe($reason)
        ->and($this->audit->has('identity.age_check_failed'))->toBeTrue();
})->with([
    ['fail', 'document_unreadable'],
    ['underage', 'under_18'],
    ['fraud', 'suspected_fraud'],
]);

it('waits when the provider is still reviewing', function (): void {
    fakeVerifier()->willReturn('review');

    runAgeCheck()->assertRedirect(route('account'))->assertSessionHas('status', 'We are checking your details. This usually takes a few minutes.');

    expect($this->user->refresh()->isAgeVerified())->toBeFalse()
        ->and(AgeVerification::query()->sole()->status)->toBe(VerificationStatus::Review);
});

it('never trusts the browser: an unknown or someone else\'s reference does nothing', function (): void {
    $this->get('/signup/age/return?ref=made-up')->assertRedirect(route('signup.age'))->assertSessionHasErrors('method');

    $other = User::factory()->create();
    actAs($other);
    $start = $this->post('/signup/age', ['method' => 'document', 'device' => 'phone']);
    parse_str((string) parse_url((string) $start->headers->get('Location'), PHP_URL_QUERY), $query);

    actAs($this->user);
    $this->get('/signup/age/return?ref='.$query['ref'])->assertSessionHasErrors('method');

    expect($this->user->refresh()->isAgeVerified())->toBeFalse()
        ->and($other->refresh()->isAgeVerified())->toBeFalse();
});

it('keeps only the reference, result and date (rule 10)', function (): void {
    runAgeCheck();

    expect(Schema::getColumnListing('age_verifications'))->toEqualCanonicalizing([
        'id', 'user_id', 'provider_ref', 'purpose', 'method', 'status', 'failure_reason', 'checked_at', 'created_at', 'updated_at',
    ]);
});

it('lets the fan skip to safe content', function (): void {
    $this->get('/signup/age')->assertSee(route('account'));
    $this->get('/account')->assertOk()->assertSee('Verify your age to see adult creators');
});

it('sends verified fans past step 3', function (): void {
    runAgeCheck();

    $this->get('/signup/age')->assertRedirect(route('account'));
    $this->post('/signup/age', ['method' => 'document'])->assertSessionHasErrors('method');
});

it('rejects an unknown method', function (): void {
    $this->post('/signup/age', ['method' => 'astrology'])->assertSessionHasErrors('method');
    expect(AgeVerification::query()->count())->toBe(0);
});
