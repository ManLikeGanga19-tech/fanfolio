<?php

declare(strict_types=1);

use App\Modules\Creators\Enums\KycStatus;
use App\Modules\Creators\Models\Creator;
use App\Modules\Creators\Models\CreatorKyc;
use Tests\Support\RecordingAuditLog;

// Decision D-14: on a computer, the creator's ID photo and face scan continue on a phone.

beforeEach(function (): void {
    $this->audit = RecordingAuditLog::swap();
    config(['fanfolio.fake.kyc_outcome' => 'pass']);
});

it('shows a computer a QR code, and the phone finishes the check', function (): void {
    $user = signedInFanWithMfa();
    $creator = Creator::factory()->create(['user_id' => $user->id]);

    $this->post('/creator/kyc', ['device' => 'computer'])->assertRedirect(route('creator.kyc'));

    $check = CreatorKyc::query()->sole();
    $handoff = session('creator.kyc_handoff');
    expect($handoff['ref'] ?? null)->toBe($check->provider_ref);

    $this->get('/creator/kyc')
        ->assertOk()
        ->assertSee('Continue on your phone')
        ->assertSee('Scan with your phone')
        ->assertSee('<svg', false)
        ->assertSee($handoff['url'])
        ->assertSee(route('creator.kyc.return', ['ref' => $check->provider_ref]), false)
        ->assertDontSee('Start ID check');

    // The phone (not signed in) opens the link, the provider sends it to "All done".
    $session = session()->all();
    auth()->logout();
    $provider = (string) $this->get($handoff['url'])->headers->get('Location');
    $this->get($provider)->assertOk()->assertSee('Go back to your computer');

    // Back on the computer: "I've finished on my phone".
    session($session);
    actAsUserId($user->id);
    $this->get(route('creator.kyc.return', ['ref' => $check->provider_ref]))->assertRedirect(route('creator.kyc'));

    expect($creator->refresh()->kyc_status)->toBe(KycStatus::Review);
    $this->get('/creator/kyc')->assertSee('ID and selfie matched')->assertDontSee('Scan with your phone');
});

it('starts a new check and a new code with "Show a new code"', function (): void {
    $user = signedInFanWithMfa();
    Creator::factory()->create(['user_id' => $user->id]);

    $this->post('/creator/kyc', ['device' => 'computer']);
    $first = session('creator.kyc_handoff')['url'] ?? null;

    $this->post('/creator/kyc', ['device' => 'computer']);
    $second = session('creator.kyc_handoff')['url'] ?? null;

    expect($second)->not->toBe($first)
        ->and(CreatorKyc::query()->count())->toBe(2);

    // The old link still opens its own (now replaced) session, which the creator flow ignores.
    $this->get('/creator/kyc')->assertSee($second)->assertDontSee($first);
});

it('drops the QR code once it has expired', function (): void {
    $user = signedInFanWithMfa();
    Creator::factory()->create(['user_id' => $user->id]);

    $this->post('/creator/kyc', ['device' => 'computer']);
    $this->travel(11)->minutes();

    $this->get('/creator/kyc')->assertDontSee('Scan with your phone')->assertSee('Check again');
});
