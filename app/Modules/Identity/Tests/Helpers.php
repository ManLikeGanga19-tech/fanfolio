<?php

declare(strict_types=1);

// Shared helpers for Identity feature tests (loaded by tests/Pest.php).

use App\Modules\Identity\Actions\Devices;
use App\Modules\Identity\Actions\RequireStepUp;
use App\Modules\Identity\Actions\Totp;
use App\Modules\Identity\Enums\Role;
use App\Modules\Identity\Models\TotpSecret;
use App\Modules\Identity\Models\User;
use Illuminate\Testing\TestResponse;
use PragmaRX\Google2FA\Google2FA;
use Tests\Support\VirtualAuthenticator;

/**
 * Signs in as $user with a fresh step-up and registers a passkey on $authenticator.
 */
function registerPasskey(User $user, VirtualAuthenticator $authenticator): void
{
    actAs($user);
    RequireStepUp::confirm(session()->driver());

    $options = test()->postJson('/account/passkeys/options')->assertOk()->json();

    expect($options['authenticatorSelection']['userVerification'] ?? null)->toBe('required')
        ->and($options['authenticatorSelection']['residentKey'] ?? null)->toBe('required');

    test()->postJson('/account/passkeys', $authenticator->attest($options))
        ->assertOk()
        ->assertJson(['redirect' => route('account.security')]);
}

function loginWithPasskey(VirtualAuthenticator $authenticator): TestResponse
{
    $options = test()->postJson('/login/passkey/options')->assertOk()->json();

    expect($options['userVerification'] ?? null)->toBe('required');

    return test()->postJson('/login/passkey', $authenticator->assert($options));
}

function totpCode(User $user, int $offset = 0): string
{
    $secret = TotpSecret::query()->where('user_id', $user->id)->sole()->secret;

    return (new Google2FA)->oathTotp($secret, Totp::currentTimestep() + $offset);
}

/**
 * Turns on the authenticator app for $user and returns the recovery codes shown.
 *
 * @return list<string>
 */
function enableTotp(User $user): array
{
    actAs($user);
    RequireStepUp::confirm(session()->driver());

    test()->post('/account/security/totp')->assertRedirect(route('account.totp.show'));
    test()->post('/account/security/totp/confirm', ['code' => totpCode($user)])
        ->assertRedirect(route('account.security.recovery-codes'));

    return session('recovery_codes');
}

/**
 * A user with a confirmed authenticator app, signed out, and their recovery codes.
 *
 * @return array{0: User, 1: list<string>}
 */
function userWithTotp(Role $role = Role::Fan): array
{
    $user = User::factory()->withRole($role)->create(['email' => 'mfa@example.test']);
    $codes = enableTotp($user);
    auth()->logout();
    session()->flush();

    return [$user, $codes];
}

/**
 * Signs in as $user in the test session, as a fresh sign-in would: the previous
 * account's device binding is dropped, so the device middleware registers this one.
 */
function actAs(User $user): void
{
    session()->forget(Devices::SESSION_KEY);
    test()->actingAs($user);
}
