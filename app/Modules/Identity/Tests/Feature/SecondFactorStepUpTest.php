<?php

declare(strict_types=1);

use App\Modules\Identity\Actions\RequireStepUp;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\Route;
use Tests\Support\RecordingAuditLog;
use Tests\Support\VirtualAuthenticator;

// Decision D-13: payouts and payout details need a passkey or authenticator code
// confirmed just before ("step-up:second-factor"). Signing in, a password or a
// recovery code is not enough.

beforeEach(function (): void {
    $this->audit = RecordingAuditLog::swap();

    Route::middleware(['web', 'auth', 'step-up:second-factor'])->group(function (): void {
        Route::get('/_test/payout', static fn (): string => 'payout page');
        Route::post('/_test/payout', static fn (): string => 'payout sent');
    });
});

it('does not count signing in, and sends the user to confirm with a second factor', function (): void {
    $user = User::factory()->create();
    enableTotp($user);

    // enableTotp confirms an ordinary step-up, as signing in does.
    expect(RequireStepUp::isFresh(session()->driver()))->toBeTrue();

    $this->get('/_test/payout')->assertRedirect(route('account.confirm'));
    expect(session('url.intended'))->toBe(url('/_test/payout'));
    $this->post('/_test/payout')->assertRedirect(route('account.confirm'));

    $this->get('/account/confirm')
        ->assertOk()
        ->assertSee('Payouts need your passkey or authenticator app, even if you signed in a moment ago.')
        ->assertSee('Code from your authenticator app')
        ->assertDontSee('Use a recovery code');
});

it('passes after an authenticator code, audited, and lapses after 10 minutes', function (): void {
    $user = User::factory()->create();
    enableTotp($user);
    $this->get('/_test/payout');

    $this->post('/account/confirm', ['code' => totpCode($user, 1)])->assertRedirect(url('/_test/payout'));

    $this->get('/_test/payout')->assertOk()->assertSee('payout page');
    expect(collect($this->audit->of('identity.step_up'))->last()['context'])->toBe(['second_factor' => true])
        ->and(session(RequireStepUp::NEEDS_SECOND_FACTOR))->toBeNull();

    $this->travel(11)->minutes();
    $this->get('/_test/payout')->assertRedirect(route('account.confirm'));
});

it('passes after the user\'s passkey', function (): void {
    $user = User::factory()->create();
    $key = VirtualAuthenticator::forApp();
    registerPasskey($user, $key);
    RequireStepUp::forget(session()->driver());

    $options = $this->postJson('/account/confirm/passkey/options')->assertOk()->json();
    $this->postJson('/account/confirm/passkey', $key->assert($options))->assertOk();

    $this->get('/_test/payout')->assertOk();
});

it('does not accept a recovery code for payouts', function (): void {
    $user = User::factory()->create();
    $codes = enableTotp($user);
    RequireStepUp::forget(session()->driver());

    $this->post('/account/confirm', ['recovery_code' => $codes[0]]);

    // Good enough for ordinary changes, not for money.
    expect(RequireStepUp::isFresh(session()->driver()))->toBeTrue()
        ->and(RequireStepUp::isFreshSecondFactor(session()->driver()))->toBeFalse();
    $this->get('/_test/payout')->assertRedirect(route('account.confirm'));
});

it('sends an account without a passkey or authenticator app to set one up', function (): void {
    $fan = User::factory()->create();
    $this->actingAs($fan);

    $this->get('/_test/payout')
        ->assertRedirect(route('account.security'))
        ->assertSessionHas('status', 'Payouts need a passkey or an authenticator app. Set one up first.');

    $this->postJson('/_test/payout')->assertForbidden()->assertJsonPath('setup', route('account.security'));
});

it('answers JSON requests with where to confirm', function (): void {
    $user = User::factory()->create();
    enableTotp($user);

    $this->postJson('/_test/payout')
        ->assertForbidden()
        ->assertJsonPath('message', 'Confirm with your passkey or authenticator app first.')
        ->assertJsonPath('confirm', route('account.confirm'));
});
