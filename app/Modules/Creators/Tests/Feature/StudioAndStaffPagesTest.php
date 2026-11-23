<?php

declare(strict_types=1);

use App\Modules\Creators\Actions\ApproveCreator;
use App\Modules\Creators\Actions\SuspendCreator;
use App\Modules\Creators\Enums\CreatorStatus;
use App\Modules\Creators\Enums\KycStatus;
use App\Modules\Creators\Filament\Admin\CreatorApprovals;
use App\Modules\Creators\Models\Creator;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
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

it('refuses the ID viewer to anyone but staff', function (): void {
    $creator = approvedCreator();

    // The creator themselves.
    $this->get(route('creator.kyc-document', ['creator' => $creator->id, 'side' => 'front']))->assertForbidden();

    signedInFan();
    $this->get(route('creator.kyc-document', ['creator' => $creator->id, 'side' => 'front']))->assertForbidden();

    expect($this->audit->of('kyc.document_viewed'))->toHaveCount(0);
});

it('lists submitted applications for staff with masked details only, and approves from the page', function (): void {
    $staff = signedInStaff();
    $user = signedInFanWithMfa();
    $creator = Creator::factory()->create(['user_id' => $user->id, 'display_name' => 'Waiting Creator', 'handle' => 'waiting.creator']);
    config(['fanfolio.fake.kyc_outcome' => 'pass']);
    $this->get((string) $this->post('/creator/kyc', ['device' => 'phone'])->headers->get('Location'));
    confirmedSecondFactor();
    $this->post('/creator/payout', ['rail' => 'bank', 'bank_name' => 'Test Bank', 'account_number' => '000011112222', 'account_name' => 'Test Person']);
    $this->post('/creator/review', ['terms' => '1', 'co_performers' => '1']);

    actAsUserId($staff->id);
    $this->get('/admin/creator-approvals')
        ->assertOk()
        ->assertSee('Creators awaiting review')
        ->assertSee('Waiting Creator')
        ->assertSee('Test Bank ····2222', false)
        ->assertSee('····0000', false)
        // Rule 13: no legal name or full numbers.
        ->assertDontSee('TEST PERSON')
        ->assertDontSee('000011112222');

    Filament::setCurrentPanel('admin');
    Livewire::test(CreatorApprovals::class)
        ->callAction(TestAction::make('approve')->table($creator))
        ->assertNotified('Approved.');

    expect($creator->refresh()->status)->toBe(CreatorStatus::Active)
        ->and($this->audit->of('creator.approved')[0]['actor'])->toBe($staff->id);
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
