<?php

declare(strict_types=1);

use App\Modules\Creators\Contracts\CreatorEligibility;
use App\Modules\Creators\Enums\CreatorStatus;
use App\Modules\Creators\Enums\KycStatus;
use App\Modules\Creators\Models\Creator;
use App\Modules\Creators\Models\PayoutMethod;
use App\Modules\Wallet\Contracts\Data\PayoutRail;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Tests\Support\RecordingAuditLog;

// Creator onboarding boards 5 (Payout method, step 4 of 4) and 6 (Review & submit).

beforeEach(function (): void {
    $this->audit = RecordingAuditLog::swap();
});

/** A signed-in applicant (MFA, authenticator code just confirmed) whose ID and selfie the fake provider passed: legal name TEST PERSON. */
function kycPassedApplicant(): Creator
{
    $user = signedInFanWithMfa();
    $creator = Creator::factory()->create(['user_id' => $user->id, 'display_name' => 'Test Creator', 'handle' => 'test.creator']);

    config(['fanfolio.fake.kyc_outcome' => 'pass']);
    test()->get((string) test()->post('/creator/kyc', ['device' => 'phone'])->headers->get('Location'));

    expect($creator->refresh()->kyc_status)->toBe(KycStatus::Review);
    confirmedSecondFactor();

    return $creator;
}

/**
 * @param  array<string, string>  $overrides
 * @return array<string, string>
 */
function bankPayout(array $overrides = []): array
{
    return ['rail' => 'bank', 'bank_name' => 'Test Bank', 'account_number' => '0000 1111 2222', 'account_name' => 'Test Person', ...$overrides];
}

/** A TRC-20 shaped address made of obviously fake characters. */
const FAKE_TRC20 = 'T'.'111111111111111111111111111111111';

it('sends applicants who have not passed the ID check back to it', function (): void {
    $user = signedInFanWithMfa();
    confirmedSecondFactor();
    Creator::factory()->create(['user_id' => $user->id]);

    $this->get('/creator/payout')->assertRedirect(route('creator.kyc'));
    $this->post('/creator/payout', bankPayout())->assertRedirect(route('creator.kyc'));
    $this->get('/creator/review')->assertRedirect(route('creator.kyc'));

    expect(PayoutMethod::query()->count())->toBe(0);
});

it('sends guests to log in and non-applicants to Become a creator', function (): void {
    $this->get('/creator/payout')->assertRedirect(route('login'));
    $this->get('/creator/review')->assertRedirect(route('login'));

    signedInFanWithMfa();
    confirmedSecondFactor();
    $this->get('/creator/payout')->assertRedirect(route('creator.start'));
    $this->get('/creator/review')->assertRedirect(route('creator.start'));
});

it('shows the payout board with the legal name to match', function (): void {
    kycPassedApplicant();

    $this->get('/creator/kyc')->assertSee(route('creator.payout'));

    $this->get('/creator/payout')
        ->assertOk()
        ->assertSee('STEP 4 OF 4 · GET PAID')
        ->assertSee('Where should we send your money?')
        ->assertSee('Kenyan banks · 1–2 business days')
        ->assertSee('TRC-20 · within 1 hour')
        ->assertSee('Within 1 business day')
        ->assertSee('It must match your ID: TEST PERSON.')
        ->assertSee('Review and submit');
});

it('needs the second factor confirmed just before seeing or changing the payout method (SEC-04, D-13)', function (): void {
    kycPassedApplicant();
    staleStepUp();

    $this->get('/creator/payout')->assertRedirect(route('account.confirm'));
    expect(session('url.intended'))->toBe(url('/creator/payout'));

    $this->post('/creator/payout', bankPayout())->assertRedirect(route('account.confirm'));
    expect(PayoutMethod::query()->count())->toBe(0);

    // Signing in (or a password or recovery code) is an ordinary step-up: not enough here.
    confirmedStepUp();
    $this->post('/creator/payout', bankPayout())->assertRedirect(route('account.confirm'));
    expect(PayoutMethod::query()->count())->toBe(0);

    confirmedSecondFactor();
    $this->post('/creator/payout', bankPayout())->assertRedirect(route('creator.review'));
    expect(PayoutMethod::query()->count())->toBe(1);

    // It lapses after 10 minutes.
    $this->travel(11)->minutes();
    $this->get('/creator/payout')->assertRedirect(route('account.confirm'));
});

it('saves a bank account encrypted, masked and audited, with the name matched', function (): void {
    $creator = kycPassedApplicant();

    $this->post('/creator/payout', bankPayout())
        ->assertRedirect(route('creator.review'))
        ->assertSessionHas('status', 'Payout method saved.');

    $method = PayoutMethod::query()->sole();
    expect($method->creator_id)->toBe($creator->id)
        ->and($method->rail)->toBe(PayoutRail::Bank)
        ->and($method->details)->toBe(['bank_name' => 'Test Bank', 'account_number' => '000011112222'])
        ->and($method->display_hint)->toBe('Test Bank ····2222')
        ->and($method->name_matches_kyc)->toBeTrue()
        ->and($method->is_default)->toBeTrue()
        ->and($this->audit->of('creator.payout_method_added'))->toHaveCount(1);

    // Rule 11: neither the number nor the name is readable in the database.
    $row = DB::table('payout_methods')->sole();
    expect($row->details)->not->toContain('000011112222')
        ->and($row->account_name)->not->toContain('Test Person');

    // The audit entry carries only the masked hint.
    expect(json_encode($this->audit->of('creator.payout_method_added')))->not->toContain('000011112222');
});

it('matches names regardless of case, punctuation and order, and flags a mismatch for staff', function (): void {
    kycPassedApplicant();

    $this->post('/creator/payout', bankPayout(['account_name' => 'person, TEST']));
    expect(PayoutMethod::query()->whereNull('revoked_at')->sole()->name_matches_kyc)->toBeTrue();

    $this->post('/creator/payout', bankPayout(['account_name' => 'Someone Else']))
        ->assertRedirect(route('creator.review'))
        ->assertSessionHas('status', 'Payout method saved. The name on the account does not match your ID, so our team will check it before your first payout.');
    expect(PayoutMethod::query()->whereNull('revoked_at')->sole()->name_matches_kyc)->toBeFalse();
});

it('saves a USDT wallet and a Paxum account', function (): void {
    kycPassedApplicant();

    $this->post('/creator/payout', ['rail' => 'usdt', 'wallet_address' => FAKE_TRC20, 'account_name' => 'Test Person'])
        ->assertRedirect(route('creator.review'));
    $usdt = PayoutMethod::query()->whereNull('revoked_at')->sole();
    expect($usdt->rail)->toBe(PayoutRail::Usdt)
        ->and($usdt->details)->toBe(['network' => 'trc20', 'address' => FAKE_TRC20])
        ->and($usdt->display_hint)->toBe('USDT wallet ····1111');

    $this->post('/creator/payout', ['rail' => 'paxum', 'paxum_email' => 'Payouts@Example.test', 'account_name' => 'Test Person'])
        ->assertRedirect(route('creator.review'));
    $paxum = PayoutMethod::query()->whereNull('revoked_at')->sole();
    expect($paxum->details)->toBe(['email' => 'payouts@example.test'])
        ->and($paxum->display_hint)->toBe('Paxum p····@example.test');
});

it('checks the details for each rail', function (array $input, string $field): void {
    kycPassedApplicant();

    $this->from('/creator/payout')->post('/creator/payout', [...$input, 'account_name' => $input['account_name'] ?? 'Test Person'])
        ->assertRedirect('/creator/payout')
        ->assertSessionHasErrors($field);

    expect(PayoutMethod::query()->count())->toBe(0);
})->with([
    'no rail' => [['bank_name' => 'Test Bank', 'account_number' => '000011112222'], 'rail'],
    'unknown rail' => [['rail' => 'cash'], 'rail'],
    'no bank' => [['rail' => 'bank', 'account_number' => '000011112222'], 'bank_name'],
    'short account number' => [['rail' => 'bank', 'bank_name' => 'Test Bank', 'account_number' => '12345'], 'account_number'],
    'letters in account number' => [['rail' => 'bank', 'bank_name' => 'Test Bank', 'account_number' => '0000ABCD2222'], 'account_number'],
    'not TRC-20' => [['rail' => 'usdt', 'wallet_address' => '0x0000000000000000000000000000000000000000'], 'wallet_address'],
    'TRC-20 with a zero' => [['rail' => 'usdt', 'wallet_address' => 'T0'.str_repeat('1', 32)], 'wallet_address'],
    'bad Paxum email' => [['rail' => 'paxum', 'paxum_email' => 'not-an-email'], 'paxum_email'],
    'no account name' => [['rail' => 'bank', 'bank_name' => 'Test Bank', 'account_number' => '000011112222', 'account_name' => ' '], 'account_name'],
]);

it('replaces the payout method: the old one is revoked, never deleted', function (): void {
    kycPassedApplicant();

    $this->post('/creator/payout', bankPayout());
    $this->post('/creator/payout', bankPayout(['account_number' => '000033334444']));

    expect(PayoutMethod::query()->count())->toBe(2)
        ->and(PayoutMethod::query()->whereNull('revoked_at')->sole()->display_hint)->toBe('Test Bank ····4444')
        ->and(PayoutMethod::query()->whereNotNull('revoked_at')->sole()->is_default)->toBeFalse()
        ->and($this->audit->of('creator.payout_method_revoked'))->toHaveCount(1);

    $this->get('/creator/payout')->assertSee('Test Bank ····4444')->assertSee('Keep this and continue');
});

it('refuses payout changes for a suspended creator', function (): void {
    $creator = kycPassedApplicant();
    $creator->forceFill(['status' => CreatorStatus::Suspended])->save();

    $this->from('/creator/payout')->post('/creator/payout', bankPayout())->assertSessionHasErrors('rail');
    expect(PayoutMethod::query()->count())->toBe(0);
});

it('shows Review & submit with the masked details and the D-02 wording', function (): void {
    kycPassedApplicant();
    $this->get('/creator/review')->assertRedirect(route('creator.payout'));

    $this->post('/creator/payout', bankPayout());

    $this->get('/creator/review')
        ->assertOk()
        ->assertSee('Check everything')
        ->assertSee('Test Creator · @test.creator')
        ->assertSee('····0000 · Captured', false)
        ->assertSee('Test Bank ····2222')
        ->assertSee('and the 20% platform fee.', false)
        ->assertSee('Everyone in my content is 18+ and will verify their identity and consent to each post before it goes live.')
        ->assertDontSee('release forms')
        ->assertDontSee('000011112222')
        ->assertSee('Submit for review');
});

it('needs both statements to submit', function (array $input, string $field): void {
    $creator = kycPassedApplicant();
    $this->post('/creator/payout', bankPayout());

    $this->from('/creator/review')->post('/creator/review', $input)->assertRedirect('/creator/review')->assertSessionHasErrors($field);

    expect($creator->refresh()->status)->toBe(CreatorStatus::Onboarding)
        ->and($creator->submitted_at)->toBeNull();
})->with([
    'neither' => [[], 'terms'],
    'no terms' => [['co_performers' => '1'], 'terms'],
    'no co-performer statement' => [['terms' => '1'], 'co_performers'],
]);

it('refuses an application without a payout method', function (): void {
    $creator = kycPassedApplicant();

    $this->post('/creator/review', ['terms' => '1', 'co_performers' => '1'])
        ->assertRedirect(route('creator.payout'))
        ->assertSessionHasErrors(['payout' => 'Add a payout method first.']);

    expect($creator->refresh()->status)->toBe(CreatorStatus::Onboarding);
});

it('refuses an application whose ID check has not passed', function (KycStatus $kyc): void {
    $user = signedInFanWithMfa();
    $creator = Creator::factory()->withStatus(CreatorStatus::Onboarding, $kyc)->create(['user_id' => $user->id]);

    $this->post('/creator/review', ['terms' => '1', 'co_performers' => '1'])->assertRedirect(route('creator.kyc'));

    expect($creator->refresh()->status)->toBe(CreatorStatus::Onboarding);
})->with([KycStatus::NotStarted, KycStatus::Pending, KycStatus::ActionNeeded, KycStatus::Rejected]);

it('submits for review once, audited, without letting the creator publish or earn', function (): void {
    $creator = kycPassedApplicant();
    $this->post('/creator/payout', bankPayout());

    $this->post('/creator/review', ['terms' => '1', 'co_performers' => '1'])
        ->assertRedirect(route('creator.status'))
        ->assertSessionHas('status', "Submitted. We'll let you know as soon as you're approved.");

    $creator->refresh();
    expect($creator->status)->toBe(CreatorStatus::Submitted)
        ->and($creator->submitted_at)->not->toBeNull()
        ->and($creator->terms_accepted_at)->not->toBeNull()
        ->and($this->audit->of('creator.submitted'))->toHaveCount(1);

    // A double tap changes nothing.
    $this->post('/creator/review', ['terms' => '1', 'co_performers' => '1'])->assertRedirect(route('creator.status'));
    expect($this->audit->of('creator.submitted'))->toHaveCount(1);

    $this->get('/creator/review')->assertRedirect(route('creator.status'));
    $this->get('/creator/status')->assertOk()->assertSee("We're reviewing your account", false)->assertDontSee('Submit for review');

    $eligibility = app(CreatorEligibility::class);
    expect($eligibility->canPublish($creator->user_id))->toBeFalse()
        ->and($eligibility->canEarn($creator->user_id))->toBeFalse();
});

it('cannot submit a suspended application', function (): void {
    $creator = kycPassedApplicant();
    $this->post('/creator/payout', bankPayout());
    $creator->forceFill(['status' => CreatorStatus::Suspended])->save();

    $this->from('/creator/review')->post('/creator/review', ['terms' => '1', 'co_performers' => '1'])->assertSessionHasErrors('submit');
    expect($creator->refresh()->status)->toBe(CreatorStatus::Suspended);
});

it('keeps one active default payout method per creator in the database', function (): void {
    $creator = kycPassedApplicant();
    $this->post('/creator/payout', bankPayout());

    expect(fn () => PayoutMethod::query()->create([
        'creator_id' => $creator->id,
        'rail' => PayoutRail::Bank,
        'details' => ['bank_name' => 'Test Bank', 'account_number' => '000099998888'],
        'account_name' => 'Test Person',
        'display_hint' => 'Test Bank ····8888',
        'is_default' => true,
    ]))->toThrow(UniqueConstraintViolationException::class);
});
