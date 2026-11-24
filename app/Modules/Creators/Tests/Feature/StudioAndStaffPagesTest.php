<?php

declare(strict_types=1);

use App\Modules\Creators\Actions\ApproveCreator;
use App\Modules\Creators\Actions\SuspendCreator;
use App\Modules\Creators\Enums\CreatorStatus;
use App\Modules\Creators\Enums\KycStatus;
use App\Modules\Creators\Models\Creator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Tests\Support\RecordingAuditLog;

// The /studio panel shell, the staff "Creators awaiting review" page (D-10) and the
// on-demand ID viewer (D-01).

beforeEach(function (): void {
    $this->audit = RecordingAuditLog::swap();
    Notification::fake();
});

/** An approved creator, signed in as themselves. Approved through the real Action. */
function approvedCreator(): Creator
{
    $staff = signedInStaff();
    $user = signedInFanWithMfa();
    $creator = Creator::factory()->create(['user_id' => $user->id]);

    config(['fanfolio.fake.kyc_outcome' => 'pass']);
    test()->get((string) test()->post('/creator/kyc', ['device' => 'phone'])->headers->get('Location'));
    confirmedSecondFactor();
    test()->post('/creator/payout', ['rail' => 'bank', 'bank_name' => 'Test Bank', 'account_number' => '000011112222', 'account_name' => 'Test Person']);
    test()->post('/creator/review', ['terms' => '1', 'co_performers' => '1']);

    app(ApproveCreator::class)->handle($creator->id, $staff->id);

    return $creator->refresh();
}

it('lets an approved creator into Studio', function (): void {
    approvedCreator();

    $this->get('/studio')->assertOk()->assertSee('Welcome to Studio');
    $this->get('/creator/status')->assertSee("You're approved", false)->assertSee(url('/studio'));
});

it('sends everyone else from Studio to the creator status screen', function (CreatorStatus $status, KycStatus $kyc): void {
    $user = signedInFanWithMfa();
    Creator::factory()->withStatus($status, $kyc)->create(['user_id' => $user->id]);

    $this->get('/studio')->assertRedirect(route('creator.status'));
})->with([
    'onboarding' => [CreatorStatus::Onboarding, KycStatus::NotStarted],
    'KYC passed, not submitted' => [CreatorStatus::Onboarding, KycStatus::Review],
    'in review' => [CreatorStatus::Submitted, KycStatus::Review],
    'rejected' => [CreatorStatus::Onboarding, KycStatus::Rejected],
]);

it('sends guests and fans who never applied away from Studio', function (): void {
    $this->get('/studio')->assertRedirect(route('login'));

    signedInFanWithMfa();
    $this->get('/studio')->assertRedirect(route('creator.status'));
    $this->get('/creator/status')->assertRedirect(route('creator.start'));
});

it('closes Studio at once on suspension and when the ID expires', function (): void {
    $creator = approvedCreator();
    $this->get('/studio')->assertOk();

    $creator->forceFill(['id_expires_at' => now()->subMinute()])->save();
    $this->get('/studio')->assertRedirect(route('creator.status'));
    $this->get('/creator/status')->assertSee('Your ID has expired')->assertSee(route('creator.kyc'));

    $creator->forceFill(['id_expires_at' => now()->addYear()])->save();
    $this->get('/studio')->assertOk();

    app(SuspendCreator::class)->handle($creator->id, signedInStaff()->id, 'leak_report');
    actAsUserId($creator->user_id);
    $this->get('/studio')->assertRedirect(route('creator.status'));
});

it('keeps the relaxed panel CSP to the panels and leaves fan pages strict (D-07)', function (): void {
    approvedCreator();

    expect((string) $this->get('/studio')->headers->get('Content-Security-Policy'))->toContain("'unsafe-eval'")
        ->and((string) $this->get('/creator/status')->headers->get('Content-Security-Policy'))->not->toContain("'unsafe-eval'");
});

it('streams the ID image from the provider to staff, uncached and audited (D-01)', function (): void {
    $creator = approvedCreator();
    $staff = signedInStaff('moderator');

    $response = $this->get(route('creator.kyc-document', ['creator' => $creator->id, 'side' => 'front']))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png');

    expect((string) $response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($this->audit->of('kyc.document_viewed'))->toHaveCount(1)
        ->and($this->audit->of('kyc.document_viewed')[0]['actor'])->toBe($staff->id)
        ->and($this->audit->of('kyc.document_viewed')[0]['context'])->toBe(['side' => 'front']);

    $this->get(route('creator.kyc-document', ['creator' => $creator->id, 'side' => 'tattoo']))->assertNotFound();
});

it('tells staff when the provider cannot return the ID image, without a server error or audit entry', function (): void {
    $creator = approvedCreator();
    signedInStaff('moderator');
    // The provider no longer knows the session (the fake keeps it in the cache).
    Cache::flush();

    $response = $this->get(route('creator.kyc-document', ['creator' => $creator->id, 'side' => 'front']))
        ->assertStatus(502)
        ->assertSee('could not return this document');

    expect((string) $response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($this->audit->of('kyc.document_viewed'))->toHaveCount(0);
});

it('refuses the ID viewer to anyone but staff', function (): void {
    $creator = approvedCreator();

    // The creator themselves.
    $this->get(route('creator.kyc-document', ['creator' => $creator->id, 'side' => 'front']))->assertForbidden();

    signedInFan();
    $this->get(route('creator.kyc-document', ['creator' => $creator->id, 'side' => 'front']))->assertForbidden();

    expect($this->audit->of('kyc.document_viewed'))->toHaveCount(0);
});

it('keeps fans and creators out of the staff page', function (): void {
    approvedCreator();

    $this->get('/admin/creator-approvals')->assertForbidden();
});

it('gives every inline script in the panels the CSP nonce, so Filament works under the policy (D-07)', function (string $path): void {
    $creator = approvedCreator();
    if ($path === '/admin/creator-approvals') {
        signedInStaff();
    } else {
        actAsUserId($creator->user_id);
    }

    $response = $this->get($path)->assertOk();
    preg_match("/'nonce-([^']+)'/", (string) $response->headers->get('Content-Security-Policy'), $nonce);
    preg_match_all('/<script(?![^>]*\bsrc=)[^>]*>/', (string) $response->getContent(), $inline);

    expect($nonce[1] ?? null)->not->toBeNull()
        ->and($inline[0])->not->toBeEmpty();
    foreach ($inline[0] as $tag) {
        expect($tag)->toContain('nonce="'.$nonce[1].'"');
    }
})->with(['/admin/creator-approvals', '/studio']);

it('points creators past onboarding to their status, and the account hub to Studio once approved', function (): void {
    $creator = approvedCreator();
    // A fresh request loads the account again, with the creator role from the approval.
    actAsUserId($creator->user_id);

    $this->get('/creator/start')->assertRedirect(route('creator.status'));
    $this->get('/account')->assertOk()
        ->assertSee('Creator Studio')
        ->assertSee(url('/studio'), false)
        ->assertDontSee('Become a creator');

    $creator->forceFill(['status' => CreatorStatus::Submitted])->save();
    $this->get('/creator/start')->assertRedirect(route('creator.status'));
});

it('shows staff a link to Admin on the account hub', function (): void {
    signedInStaff();

    $this->get('/account')->assertOk()
        ->assertSee(url('/admin'), false)
        ->assertDontSee('Become a creator');
});
