<?php

declare(strict_types=1);

use App\Modules\Identity\Contracts\PhoneHandoff;
use App\Modules\Identity\Enums\Role;
use App\Modules\Identity\Models\EmailVerification;
use App\Modules\Identity\Models\User;
use App\Support\DevMode;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Notification;
use Tests\Support\RecordingAuditLog;

// Decision D-14: the ID and face scan happen on a phone (QR handoff from a computer),
// and development mode accepts anything, locally only.

beforeEach(function (): void {
    $this->audit = RecordingAuditLog::swap();
    Notification::fake();
});

function asLocalDevMode(bool $on = true): void
{
    app()->detectEnvironment(static fn (): string => 'local');
    config(['fanfolio.dev_mode' => $on]);
    // Laravel skips CSRF checks only when the environment is "testing".
    test()->withoutMiddleware(ValidateCsrfToken::class);
}

/** The single-use phone link shown on the age check page, from the session. */
function ageHandoffUrl(): string
{
    $handoff = session('identity.age_handoff');

    return is_array($handoff) && is_string($handoff['url'] ?? null) ? $handoff['url'] : '';
}

it('shows a computer a QR code for the age check instead of leaving the page', function (): void {
    $fan = User::factory()->create();
    actAs($fan);

    $this->post('/signup/age', ['method' => 'selfie_age', 'device' => 'computer'])->assertRedirect(route('signup.age'));

    $link = ageHandoffUrl();
    expect($link)->toStartWith(url('/verify/on-phone/'));

    $this->get('/signup/age')
        ->assertOk()
        ->assertSee('Scan with your phone')
        ->assertSee('<svg', false)
        ->assertSee($link)
        ->assertSee("I've finished on my phone", false)
        ->assertSee('Show a new code')
        ->assertDontSee('Continue on this computer');

    // Without the script, the server assumes a computer too.
    $this->post('/signup/age', ['method' => 'document'])->assertRedirect(route('signup.age'));
    expect(ageHandoffUrl())->not->toBe($link);
});

it('sends a phone straight to the provider', function (): void {
    actAs(User::factory()->create());

    $start = $this->post('/signup/age', ['method' => 'selfie_age', 'device' => 'phone']);

    expect((string) $start->headers->get('Location'))->toStartWith(route('signup.age.return'))
        ->and(session('identity.age_handoff'))->toBeNull();
});

it('opens the provider once from the phone link, without signing in, for 10 minutes', function (): void {
    $fan = User::factory()->create();
    actAs($fan);
    $this->post('/signup/age', ['method' => 'selfie_age', 'device' => 'computer']);
    $link = ageHandoffUrl();

    auth()->logout();

    $open = $this->get($link);
    expect((string) $open->headers->get('Location'))->toContain('ref=fake_kyc_')
        ->and($this->audit->has('identity.phone_handoff_opened', $fan->id))->toBeTrue();

    // Single use.
    $this->get($link)->assertStatus(410)->assertSee('This code has expired');

    // And 10 minutes at most.
    actAs($fan);
    $this->post('/signup/age', ['method' => 'selfie_age', 'device' => 'computer']);
    $second = ageHandoffUrl();
    $this->travel(11)->minutes();
    $this->get($second)->assertStatus(410);

    $this->get('/verify/on-phone/not-a-token')->assertStatus(410);
});

it('tells the phone to go back to the computer, and sends the same signed-in browser on', function (): void {
    $fan = User::factory()->create();
    $done = app(PhoneHandoff::class)->returnUrl($fan->id, route('signup.age.return'));

    // The phone: not signed in.
    $this->get($done.'&ref=fake_kyc_1')->assertOk()->assertSee('All done')->assertSee('Go back to your computer');

    // The computer's own browser, signed in as the same account.
    actAs($fan);
    $this->get($done.'&ref=fake_kyc_1')->assertRedirect(route('signup.age.return', ['ref' => 'fake_kyc_1']));

    // Someone else's account gets the plain page.
    actAs(User::factory()->create());
    $this->get($done.'&ref=fake_kyc_1')->assertOk()->assertSee('All done');

    // A tampered "next" fails the signature: no open redirect.
    $this->get(str_replace(urlencode(route('signup.age.return')), urlencode('https://evil.example.test'), $done))->assertForbidden();
});

it('is off outside local, even with the switch on, and refuses to boot in production', function (): void {
    config(['fanfolio.dev_mode' => true]);
    expect(DevMode::enabled())->toBeFalse();

    app()->detectEnvironment(static fn (): string => 'production');
    expect(static fn () => DevMode::assertSafe())->toThrow(RuntimeException::class, 'FANFOLIO_DEV_MODE must be off in production.');

    asLocalDevMode();
    expect(DevMode::enabled())->toBeTrue();
    DevMode::assertSafe();
});

it('accepts any 6-digit email code in development mode, audited as such', function (): void {
    $fan = User::factory()->create(['email_verified_at' => null]);
    actAs($fan);
    asLocalDevMode();

    $this->get('/signup/email')->assertOk()->assertSee('Development mode: any 6-digit code works.');
    $this->post('/signup/email/verify', ['code' => '123456'])->assertRedirect(route('signup.age'));

    expect($fan->refresh()->hasVerifiedEmail())->toBeTrue()
        ->and($this->audit->of('identity.email_verified')[0]['context'])->toBe(['dev_mode' => true])
        ->and(EmailVerification::query()->count())->toBe(0);
});

it('still checks the code when development mode is off', function (): void {
    $fan = User::factory()->create(['email_verified_at' => null]);
    actAs($fan);
    asLocalDevMode(false);

    $this->post('/signup/email/send');
    $this->post('/signup/email/verify', ['code' => '123456'])->assertSessionHasErrors('code');
    $this->get('/signup/email')->assertDontSee('Development mode');
});

it('shows the stand-in capture page in development mode, which accepts anything and uploads nothing', function (): void {
    $fan = User::factory()->create();
    actAs($fan);
    asLocalDevMode();

    $start = $this->post('/signup/age', ['method' => 'selfie_age', 'device' => 'phone']);
    $capture = (string) $start->headers->get('Location');
    expect($capture)->toContain('/_fake/kyc/');

    $page = $this->get($capture)->assertOk()
        ->assertSee('Development stand-in for the verification partner')
        ->assertSee('Selfie liveness check')
        ->assertSee('Start camera');

    // Rule 10: the image inputs have no name, so nothing is ever sent.
    preg_match_all('/<input[^>]*type="file"[^>]*>/', (string) $page->getContent(), $files);
    expect($files[0])->toHaveCount(2);
    foreach ($files[0] as $input) {
        expect($input)->not->toContain('name=');
    }

    // Finish with no images at all: accepted, back to the return URL, and the age check passes.
    $finish = $this->post($capture, ['outcome' => 'pass']);
    expect((string) $finish->headers->get('Location'))->toStartWith(route('signup.age.return'));
    $this->get((string) $finish->headers->get('Location'));

    expect($fan->refresh()->isAgeVerified())->toBeTrue();
});

it('keeps the check pending in development mode until the stand-in capture page is submitted', function (): void {
    $fan = User::factory()->create();
    actAs($fan);
    asLocalDevMode();

    $this->post('/signup/age', ['method' => 'document', 'device' => 'computer']);
    $handoff = session('identity.age_handoff');
    $ref = is_array($handoff) && is_string($handoff['ref'] ?? null) ? $handoff['ref'] : '';
    expect($ref)->toStartWith('fake_kyc_');

    // "I've finished on my phone" before anything was captured: still pending.
    $this->get(route('signup.age.return', ['ref' => $ref]));
    expect($fan->refresh()->isAgeVerified())->toBeFalse();

    $this->post('/_fake/kyc/'.$ref, ['outcome' => 'pass']);
    $this->get(route('signup.age.return', ['ref' => $ref]));
    expect($fan->refresh()->isAgeVerified())->toBeTrue();
});

it('offers "continue on this computer" only in development mode', function (): void {
    actAs(User::factory()->create());
    asLocalDevMode();

    $this->post('/signup/age', ['method' => 'selfie_age', 'device' => 'computer']);
    $this->get('/signup/age')->assertSee('Continue on this computer (development only)');
});

it('hides the stand-in capture page outside development mode', function (): void {
    actAs(User::factory()->create());

    $this->get('/_fake/kyc/fake_kyc_anything')->assertNotFound();
});

it('makes staff with fanfolio:dev-staff in development mode only', function (): void {
    $user = User::factory()->create(['email' => 'future.admin@example.test']);

    $this->artisan('fanfolio:dev-staff', ['email' => 'future.admin@example.test'])->assertFailed();
    expect($user->refresh()->role)->toBe(Role::Fan);

    asLocalDevMode();
    $this->artisan('fanfolio:dev-staff', ['email' => 'future.admin@example.test', '--role' => 'creator'])->assertFailed();
    $this->artisan('fanfolio:dev-staff', ['email' => 'nobody@example.test'])->assertFailed();

    $this->artisan('fanfolio:dev-staff', ['email' => 'future.admin@example.test', '--role' => 'moderator'])->assertSuccessful();
    expect($user->refresh()->role)->toBe(Role::Moderator)
        ->and($this->audit->of('identity.role_changed')[0]['context'])->toBe(['from' => 'fan', 'to' => 'moderator', 'dev_mode' => true]);
});
