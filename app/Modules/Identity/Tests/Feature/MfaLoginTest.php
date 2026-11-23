<?php

declare(strict_types=1);

use App\Modules\Identity\Actions\RecoveryCodes;
use Tests\Support\RecordingAuditLog;

const MFA_PASSWORD = 'password-for-tests-only';

beforeEach(function (): void {
    $this->audit = RecordingAuditLog::swap();
});

it('does not sign in with only a password when MFA is on', function (): void {
    userWithTotp();

    $this->post('/login', ['email' => 'mfa@example.test', 'password' => MFA_PASSWORD])
        ->assertRedirect(route('login.mfa'));

    $this->assertGuest();
    $this->get('/account')->assertRedirect(route('login'));
});

it('signs in after the authenticator code', function (): void {
    [$user] = userWithTotp();

    $this->post('/login', ['email' => 'mfa@example.test', 'password' => MFA_PASSWORD]);
    $this->get('/login/mfa')->assertOk()->assertSee('Code from your authenticator app');

    $this->post('/login/mfa', ['code' => totpCode($user, 1)])->assertRedirect(route('account'));

    $this->assertAuthenticatedAs($user);
    expect($this->audit->of('identity.login')[0]['context'])->toBe(['method' => 'password+totp']);
});

it('signs in with a recovery code, once', function (): void {
    [$user, $codes] = userWithTotp();

    $this->post('/login', ['email' => 'mfa@example.test', 'password' => MFA_PASSWORD]);
    $this->post('/login/mfa', ['recovery_code' => strtolower($codes[0])])->assertRedirect(route('account'));
    $this->assertAuthenticatedAs($user);

    $this->post('/logout');
    $this->post('/login', ['email' => 'mfa@example.test', 'password' => MFA_PASSWORD]);
    $this->post('/login/mfa', ['recovery_code' => $codes[0]])->assertSessionHasErrors('recovery_code');
    $this->assertGuest();

    expect(app(RecoveryCodes::class)->remaining($user))->toBe(9);
});

it('records a wrong second factor', function (): void {
    [$user] = userWithTotp();

    $this->post('/login', ['email' => 'mfa@example.test', 'password' => MFA_PASSWORD]);
    $this->post('/login/mfa', ['code' => '000000'])->assertSessionHasErrors('code');

    $this->assertGuest();
    expect($this->audit->has('identity.login_failed', $user->id))->toBeTrue();
});

it('expires the pending login after 5 minutes', function (): void {
    [$user] = userWithTotp();

    $this->post('/login', ['email' => 'mfa@example.test', 'password' => MFA_PASSWORD]);
    $this->travel(6)->minutes();

    $this->post('/login/mfa', ['code' => totpCode($user)])->assertRedirect(route('login'));
    $this->assertGuest();
});

it('sends the second step back to log in without a pending password', function (): void {
    $this->get('/login/mfa')->assertRedirect(route('login'));
    $this->post('/login/mfa', ['code' => '123456'])->assertRedirect(route('login'));
});

it('rate limits second-factor guesses (SEC-14)', function (): void {
    userWithTotp();
    // Setting up the app used the same per-account bucket; start this test from zero.
    cache()->flush();
    $this->post('/login', ['email' => 'mfa@example.test', 'password' => MFA_PASSWORD]);

    foreach (range(1, 5) as $attempt) {
        $this->post('/login/mfa', ['code' => '000000'])->assertStatus(302);
    }

    $this->post('/login/mfa', ['code' => '000000'])->assertStatus(429)->assertHeader('Retry-After');
});
