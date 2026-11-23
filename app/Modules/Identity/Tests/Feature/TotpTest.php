<?php

declare(strict_types=1);

use App\Modules\Identity\Actions\RequireStepUp;
use App\Modules\Identity\Actions\Totp;
use App\Modules\Identity\Models\TotpSecret;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Support\RecordingAuditLog;

beforeEach(function (): void {
    $this->audit = RecordingAuditLog::swap();
});

it('shows a set-up key and link, then turns on with a valid code', function (): void {
    $user = User::factory()->create(['username' => 'totp_fan']);
    $this->actingAs($user);
    RequireStepUp::confirm(session()->driver());

    $this->post('/account/security/totp');

    $this->get('/account/security/totp')
        ->assertOk()
        ->assertSee('otpauth://totp/FanFolio:%40totp_fan?secret=', false)
        ->assertSee('OR ENTER THIS KEY');

    $this->post('/account/security/totp/confirm', ['code' => totpCode($user)]);

    expect($user->refresh()->mfa_enabled)->toBeTrue()
        ->and($this->audit->has('identity.totp_added', $user->id))->toBeTrue();
});

it('shows ten recovery codes once', function (): void {
    $user = User::factory()->create();
    $codes = enableTotp($user);

    expect($codes)->toHaveCount(10)
        ->each->toMatch('/^[2-9A-Z]{5}-[2-9A-Z]{5}$/');

    $this->get('/account/security/recovery-codes')->assertOk()->assertSee($codes[0]);
    // Flash data is gone on the next visit.
    $this->get('/account/security/recovery-codes')->assertRedirect(route('account.security'));

    // Stored only as hashes.
    expect(DB::table('mfa_recovery_codes')->pluck('code_hash')->implode(','))->not->toContain($codes[0]);
});

it('keeps the TOTP secret encrypted at rest', function (): void {
    $user = User::factory()->create();
    enableTotp($user);

    $stored = DB::table('mfa_totp')->value('secret');
    expect($stored)->not->toBe(TotpSecret::query()->sole()->secret);
});

it('refuses a wrong code when turning on', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);
    RequireStepUp::confirm(session()->driver());
    $this->post('/account/security/totp');

    $this->post('/account/security/totp/confirm', ['code' => '000000'])->assertSessionHasErrors('code');
    expect($user->refresh()->mfa_enabled)->toBeFalse();
});

it('accepts each code only once', function (): void {
    $user = User::factory()->create();
    enableTotp($user);
    $totp = app(Totp::class);

    $next = totpCode($user, 1);

    expect($totp->verify($user, $next))->toBeTrue()
        ->and($totp->verify($user, $next))->toBeFalse()
        // An older code than one already used is refused too.
        ->and($totp->verify($user, totpCode($user)))->toBeFalse();
});

it('lets a fan remove the app, which turns MFA off', function (): void {
    $user = User::factory()->create();
    enableTotp($user);

    $this->delete('/account/security/totp')->assertRedirect(route('account.security'));

    expect($user->refresh()->mfa_enabled)->toBeFalse()
        ->and($this->audit->has('identity.totp_removed', $user->id))->toBeTrue();
});
