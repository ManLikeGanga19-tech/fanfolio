<?php

declare(strict_types=1);

use App\Modules\Identity\Actions\RequireStepUp;
use App\Modules\Identity\Enums\AccountStatus;
use App\Modules\Identity\Enums\Role;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Support\RecordingAuditLog;
use Tests\Support\VirtualAuthenticator;

beforeEach(function (): void {
    $this->audit = RecordingAuditLog::swap();
});

it('registers a passkey, which turns MFA on', function (): void {
    $user = User::factory()->create();
    $authenticator = VirtualAuthenticator::forApp();

    registerPasskey($user, $authenticator);

    $user->refresh();
    expect($user->mfa_enabled)->toBeTrue()
        ->and($user->webAuthnCredentials()->count())->toBe(1)
        ->and($this->audit->has('identity.passkey_added', $user->id))->toBeTrue()
        ->and($this->audit->has('identity.mfa_enabled', $user->id))->toBeTrue();

    // Stored encrypted: the public key column is not readable as-is.
    expect(DB::table('webauthn_credentials')->value('public_key'))->not->toContain('BEGIN PUBLIC KEY');
});

it('logs in with a passkey, no email or password needed', function (): void {
    $user = User::factory()->create();
    $authenticator = VirtualAuthenticator::forApp();
    registerPasskey($user, $authenticator);
    auth()->logout();

    loginWithPasskey($authenticator)->assertOk()->assertJson(['redirect' => route('account')]);

    $this->assertAuthenticatedAs($user);
    expect($this->audit->of('identity.login'))->toHaveCount(1)
        ->and($this->audit->of('identity.login')[0]['context'])->toBe(['method' => 'passkey']);
});

it('refuses an assertion with a forged signature', function (): void {
    $user = User::factory()->create();
    $authenticator = VirtualAuthenticator::forApp();
    registerPasskey($user, $authenticator);
    auth()->logout();

    $options = $this->postJson('/login/passkey/options')->json();
    $payload = $authenticator->assert($options);
    $payload['response']['signature'] = base64_encode(random_bytes(70));

    $this->postJson('/login/passkey', $payload)->assertStatus(422);
    $this->assertGuest();
});

it('refuses a replayed assertion', function (): void {
    $user = User::factory()->create();
    $authenticator = VirtualAuthenticator::forApp();
    registerPasskey($user, $authenticator);
    auth()->logout();

    $options = $this->postJson('/login/passkey/options')->json();
    $payload = $authenticator->assert($options);

    $this->postJson('/login/passkey', $payload)->assertOk();
    auth()->logout();

    // The challenge was used up; the same signed response cannot sign in again.
    $this->postJson('/login/passkey', $payload)->assertStatus(422);
    $this->assertGuest();
});

it('refuses an assertion made without user verification', function (): void {
    $user = User::factory()->create();
    $authenticator = VirtualAuthenticator::forApp();
    registerPasskey($user, $authenticator);
    auth()->logout();

    $options = $this->postJson('/login/passkey/options')->json();

    $this->postJson('/login/passkey', $authenticator->assert($options, userVerified: false))->assertStatus(422);
    $this->assertGuest();
});

it('refuses a passkey from a suspended account', function (): void {
    $user = User::factory()->create();
    $authenticator = VirtualAuthenticator::forApp();
    registerPasskey($user, $authenticator);
    auth()->logout();

    $user->forceFill(['status' => AccountStatus::Suspended])->save();

    loginWithPasskey($authenticator)->assertStatus(422);
    $this->assertGuest();
});

it('needs a recent step-up to add a passkey', function (): void {
    $this->actingAs(User::factory()->create());

    $this->postJson('/account/passkeys/options')
        ->assertForbidden()
        ->assertJson(['confirm' => route('account.confirm')]);
});

it('keeps a creator from removing their only second factor', function (): void {
    $creator = User::factory()->withRole(Role::Creator)->create();
    $authenticator = VirtualAuthenticator::forApp();
    registerPasskey($creator, $authenticator);

    $this->delete('/account/passkeys/'.$authenticator->id())->assertSessionHasErrors('passkey');

    expect($creator->refresh()->webAuthnCredentials()->count())->toBe(1);
});

it('lets a fan remove a passkey, which turns MFA off', function (): void {
    $fan = User::factory()->create();
    $authenticator = VirtualAuthenticator::forApp();
    registerPasskey($fan, $authenticator);

    $this->delete('/account/passkeys/'.$authenticator->id())->assertRedirect(route('account.security'));

    expect($fan->refresh()->mfa_enabled)->toBeFalse()
        ->and($this->audit->has('identity.passkey_removed', $fan->id))->toBeTrue();
});

it('cannot remove someone else\'s passkey (SEC-06)', function (): void {
    $owner = User::factory()->create();
    $authenticator = VirtualAuthenticator::forApp();
    registerPasskey($owner, $authenticator);

    $intruder = User::factory()->create();
    actAs($intruder);
    RequireStepUp::confirm(session()->driver());

    $this->delete('/account/passkeys/'.$authenticator->id())->assertSessionHasErrors('passkey');

    expect($owner->webAuthnCredentials()->count())->toBe(1);
});
