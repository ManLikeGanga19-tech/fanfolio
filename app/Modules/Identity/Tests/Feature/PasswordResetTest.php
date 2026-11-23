<?php

declare(strict_types=1);

use App\Modules\Identity\Actions\Devices;
use App\Modules\Identity\Enums\AccountStatus;
use App\Modules\Identity\Enums\Role;
use App\Modules\Identity\Http\Controllers\PasswordResetController;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Models\UserDevice;
use App\Modules\Identity\Notifications\PasswordResetLink;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\Support\RecordingAuditLog;

// "Forgot password?" on the log in screen.

beforeEach(function (): void {
    $this->audit = RecordingAuditLog::swap();
    Notification::fake();
});

function requestResetToken(User $user): string
{
    test()->post('/forgot-password', ['email' => $user->email])->assertRedirect(route('password.request'));

    $token = null;
    Notification::assertSentTo($user, PasswordResetLink::class, function (PasswordResetLink $n) use (&$token): bool {
        $token = $n->token;

        return true;
    });

    return (string) $token;
}

it('shows the forgot password link on log in', function (): void {
    $this->get('/login')->assertOk()->assertSee('Forgot password?')->assertSee(route('password.request'), false);
});

it('gives the same answer for known and unknown emails', function (): void {
    $user = User::factory()->create(['email' => 'fan@example.test']);

    $this->post('/forgot-password', ['email' => 'fan@example.test'])
        ->assertRedirect(route('password.request'))
        ->assertSessionHas('status', PasswordResetController::SENT);

    $this->post('/forgot-password', ['email' => 'nobody@example.test'])
        ->assertRedirect(route('password.request'))
        ->assertSessionHas('status', PasswordResetController::SENT);

    Notification::assertSentToTimes($user, PasswordResetLink::class, 1);
    Notification::assertCount(1);
    expect($this->audit->has('identity.password_reset_requested', $user->id))->toBeTrue();
});

it('stores only a hash of the reset token', function (): void {
    $user = User::factory()->create();
    $token = requestResetToken($user);

    expect(DB::table('password_reset_tokens')->value('token'))->not->toBe($token);
});

it('sends no link to a suspended account', function (): void {
    $user = User::factory()->create(['status' => AccountStatus::Suspended]);

    $this->post('/forgot-password', ['email' => $user->email])->assertSessionHas('status', PasswordResetController::SENT);

    Notification::assertNothingSent();
});

it('resets the password, signs out every device, and needs the new password', function (): void {
    $user = User::factory()->create(['email' => 'fan@example.test']);
    $deviceId = app(Devices::class)->register($user, 'device-token-of-a-stolen-session', 'Mozilla/5.0', '203.0.113.5')->id;

    $token = requestResetToken($user);
    $this->get(route('password.reset', ['token' => $token, 'email' => $user->email]))->assertOk()->assertSee('Choose a new password');

    $this->post('/reset-password', [
        'token' => $token,
        'email' => 'fan@example.test',
        'password' => 'a-fresh-long-passphrase',
        'password_confirmation' => 'a-fresh-long-passphrase',
    ])->assertRedirect(route('login'));

    $user->refresh();
    expect(Hash::check('a-fresh-long-passphrase', (string) $user->password))->toBeTrue()
        ->and(UserDevice::query()->whereKey($deviceId)->value('revoked_at'))->not->toBeNull()
        ->and($this->audit->of('identity.password_reset')[0]['context'])->toBe(['devices_signed_out' => 1]);
    $this->assertGuest();

    // The link works once.
    $this->post('/reset-password', [
        'token' => $token,
        'email' => 'fan@example.test',
        'password' => 'another-long-passphrase',
        'password_confirmation' => 'another-long-passphrase',
    ])->assertSessionHasErrors(['email' => 'This reset link has expired or was already used. Ask for a new one.']);
});

it('applies the sign-up password rules and keeps the link usable on refusal', function (): void {
    $user = User::factory()->create();
    $token = requestResetToken($user);

    $this->post('/reset-password', ['token' => $token, 'email' => $user->email, 'password' => 'short', 'password_confirmation' => 'short'])
        ->assertSessionHasErrors(['password' => 'At least 12 characters.']);

    $this->post('/reset-password', ['token' => $token, 'email' => $user->email, 'password' => 'password1234', 'password_confirmation' => 'password1234'])
        ->assertSessionHasErrors('password');

    $this->post('/reset-password', ['token' => $token, 'email' => $user->email, 'password' => 'a-fresh-long-passphrase', 'password_confirmation' => 'a-fresh-long-passphrase'])
        ->assertRedirect(route('login'));
});

it('refuses a wrong or expired token', function (): void {
    $user = User::factory()->create();
    $token = requestResetToken($user);

    $data = ['email' => $user->email, 'password' => 'a-fresh-long-passphrase', 'password_confirmation' => 'a-fresh-long-passphrase'];

    $this->post('/reset-password', ['token' => 'not-the-token', ...$data])->assertSessionHasErrors('email');

    $this->travel(61)->minutes();
    $this->post('/reset-password', ['token' => $token, ...$data])->assertSessionHasErrors('email');
});

it('lets a passkey-only account add a password, but the second factor is still asked', function (): void {
    $admin = User::factory()->withRole(Role::Admin)->create(['password' => null]);
    enableTotp($admin);
    auth()->logout();
    session()->flush();

    $token = requestResetToken($admin);
    $this->post('/reset-password', [
        'token' => $token,
        'email' => $admin->email,
        'password' => 'a-fresh-long-passphrase',
        'password_confirmation' => 'a-fresh-long-passphrase',
    ])->assertRedirect(route('login'));

    $this->post('/login', ['email' => $admin->email, 'password' => 'a-fresh-long-passphrase'])
        ->assertRedirect(route('login.mfa'));
    $this->assertGuest();
});

it('rate limits reset requests', function (): void {
    cache()->flush();

    foreach (range(1, 5) as $ignored) {
        $this->post('/forgot-password', ['email' => 'someone@example.test'])->assertRedirect();
    }

    $this->post('/forgot-password', ['email' => 'someone@example.test'])
        ->assertTooManyRequests()
        ->assertHeader('Retry-After');
});
