<?php

declare(strict_types=1);

use App\Modules\Identity\Actions\RegisterFanWithPasskey;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Support\RecordingAuditLog;
use Tests\Support\VirtualAuthenticator;

// Sign up step 1, "Continue with a passkey" (screens: Sign up, step 1 of 3).

beforeEach(function (): void {
    $this->audit = RecordingAuditLog::swap();
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function passkeySignUpData(array $overrides = []): array
{
    return [
        'email' => 'new.fan@example.test',
        'username' => '@new_fan',
        'adult_and_terms' => '1',
        ...$overrides,
    ];
}

it('creates a passwordless account with a passkey, MFA on, and shows recovery codes first', function (): void {
    $authenticator = VirtualAuthenticator::forApp();

    $options = $this->postJson('/signup/passkey/options', passkeySignUpData())->assertOk()->json();

    expect($options['authenticatorSelection']['userVerification'] ?? null)->toBe('required')
        ->and($options['authenticatorSelection']['residentKey'] ?? null)->toBe('required')
        ->and($options['user']['name'] ?? null)->toBe('@new_fan')
        ->and(User::query()->count())->toBe(0);

    $this->postJson('/signup/passkey', $authenticator->attest($options))
        ->assertOk()
        ->assertJson(['redirect' => route('account.security.recovery-codes')]);

    $user = User::query()->sole();
    expect($user->email)->toBe('new.fan@example.test')
        ->and($user->username)->toBe('new_fan')
        ->and($user->password)->toBeNull()
        ->and($user->mfa_enabled)->toBeTrue()
        ->and($user->webAuthnCredentials()->count())->toBe(1)
        ->and($this->audit->of('identity.registered')[0]['context'])->toBe(['method' => 'passkey'])
        ->and($this->audit->has('identity.passkey_added', $user->id))->toBeTrue();

    $this->assertAuthenticatedAs($user);

    // Recovery codes, then on to the phone step.
    $this->get(route('account.security.recovery-codes'))
        ->assertOk()
        ->assertSee(route('signup.phone'), false);

    // The new passkey signs in.
    auth()->logout();
    session()->flush();
    loginWithPasskey($authenticator)->assertOk();
    $this->assertAuthenticatedAs($user);
});

it('checks the account fields before asking for a passkey', function (): void {
    User::factory()->create(['email' => 'taken@example.test']);

    $this->postJson('/signup/passkey/options', passkeySignUpData(['email' => 'TAKEN@example.test']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email' => 'This email already has an account. Log in instead.']);

    $this->postJson('/signup/passkey/options', passkeySignUpData(['adult_and_terms' => null]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('adult_and_terms');

    expect(session(RegisterFanWithPasskey::SESSION_KEY))->toBeNull();
});

it('creates nothing when the passkey is refused', function (): void {
    $authenticator = VirtualAuthenticator::forApp();
    $options = $this->postJson('/signup/passkey/options', passkeySignUpData())->assertOk()->json();

    $attestation = $authenticator->attest([...$options, 'challenge' => rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=')]);

    $this->postJson('/signup/passkey', $attestation)->assertUnprocessable();

    expect(User::query()->count())->toBe(0)
        ->and(DB::table('webauthn_credentials')->count())->toBe(0);
    $this->assertGuest();
});

it('times out a sign-up left waiting', function (): void {
    $authenticator = VirtualAuthenticator::forApp();
    $options = $this->postJson('/signup/passkey/options', passkeySignUpData())->assertOk()->json();

    $this->travel(6)->minutes();

    $this->postJson('/signup/passkey', $authenticator->attest($options))
        ->assertUnprocessable()
        ->assertJson(['message' => 'Your sign-up timed out. Start again.']);

    expect(User::query()->count())->toBe(0);
});

it('refuses the passkey when the email was taken in the meantime', function (): void {
    $authenticator = VirtualAuthenticator::forApp();
    $options = $this->postJson('/signup/passkey/options', passkeySignUpData())->assertOk()->json();

    User::factory()->create(['email' => 'new.fan@example.test']);

    $this->postJson('/signup/passkey', $authenticator->attest($options))
        ->assertUnprocessable()
        ->assertJson(['message' => 'This email or username is no longer free. Start again.']);

    expect(User::query()->count())->toBe(1)
        ->and(DB::table('webauthn_credentials')->count())->toBe(0);
});

it('does not let a passkey-only account log in with any password', function (): void {
    $authenticator = VirtualAuthenticator::forApp();
    $options = $this->postJson('/signup/passkey/options', passkeySignUpData())->assertOk()->json();
    $this->postJson('/signup/passkey', $authenticator->attest($options))->assertOk();
    auth()->logout();
    session()->flush();

    $this->post('/login', ['email' => 'new.fan@example.test', 'password' => ''])->assertSessionHasErrors();
    $this->post('/login', ['email' => 'new.fan@example.test', 'password' => 'anything-at-all-123'])
        ->assertSessionHasErrors(['email' => 'Email or password is not right.']);

    $this->assertGuest();
});
