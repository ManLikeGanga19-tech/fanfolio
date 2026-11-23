<?php

declare(strict_types=1);

use App\Modules\Identity\Actions\RequireStepUp;
use App\Modules\Identity\Models\User;
use Tests\Support\RecordingAuditLog;
use Tests\Support\VirtualAuthenticator;

const STEP_UP_PASSWORD = 'password-for-tests-only';

beforeEach(function (): void {
    $this->audit = RecordingAuditLog::swap();
});

it('counts signing in as a fresh confirmation', function (): void {
    User::factory()->create(['email' => 'fresh@example.test']);

    $this->post('/login', ['email' => 'fresh@example.test', 'password' => STEP_UP_PASSWORD]);

    $this->post('/account/security/totp')->assertRedirect(route('account.totp.show'));
});

it('asks to confirm again after 10 minutes', function (): void {
    User::factory()->create(['email' => 'stale@example.test']);
    $this->post('/login', ['email' => 'stale@example.test', 'password' => STEP_UP_PASSWORD]);

    $this->travel(11)->minutes();

    $this->post('/account/security/totp')->assertRedirect(route('account.confirm'));
    $this->get('/account/security/totp')->assertRedirect(route('account.confirm'));
});

it('lets a fan without MFA confirm with their password, then returns to the page', function (): void {
    $fan = User::factory()->create();
    $this->actingAs($fan);

    $this->get('/account/security/totp')->assertRedirect(route('account.confirm'));
    $this->get('/account/confirm')->assertSee('Password');

    $this->post('/account/confirm', ['password' => 'wrong-password'])->assertSessionHasErrors('code');
    $this->post('/account/confirm', ['password' => STEP_UP_PASSWORD])->assertRedirect(route('account.totp.show'));

    expect(RequireStepUp::isFresh(session()->driver()))->toBeTrue()
        ->and($this->audit->has('identity.step_up', $fan->id))->toBeTrue();
});

it('does not accept the password once MFA is on', function (): void {
    $user = User::factory()->create();
    enableTotp($user);
    RequireStepUp::forget(session()->driver());

    $this->post('/account/confirm', ['password' => STEP_UP_PASSWORD])->assertSessionHasErrors('code');
    expect(RequireStepUp::isFresh(session()->driver()))->toBeFalse();

    $this->post('/account/confirm', ['code' => totpCode($user, 1)]);
    expect(RequireStepUp::isFresh(session()->driver()))->toBeTrue();
});

it('confirms with the user\'s own passkey only', function (): void {
    $owner = User::factory()->create();
    $ownerKey = VirtualAuthenticator::forApp();
    registerPasskey($owner, $ownerKey);

    $other = User::factory()->create();
    $otherKey = VirtualAuthenticator::forApp();
    registerPasskey($other, $otherKey);

    // Signed in as $other, try to step up with $owner's passkey.
    RequireStepUp::forget(session()->driver());
    $options = $this->postJson('/account/confirm/passkey/options')->assertOk()->json();
    $this->postJson('/account/confirm/passkey', $ownerKey->assert($options))->assertStatus(422);
    expect(RequireStepUp::isFresh(session()->driver()))->toBeFalse();

    $options = $this->postJson('/account/confirm/passkey/options')->json();
    $this->postJson('/account/confirm/passkey', $otherKey->assert($options))->assertOk();
    expect(RequireStepUp::isFresh(session()->driver()))->toBeTrue();
});
