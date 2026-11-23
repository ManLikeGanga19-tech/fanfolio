<?php

declare(strict_types=1);

use App\Modules\Identity\Actions\Devices;
use App\Modules\Identity\Events\DeviceRevoked;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Models\UserDevice;
use App\Modules\Identity\Support\DeviceCookie;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\RecordingAuditLog;
use Tests\Support\VirtualAuthenticator;

// SEC-05 and decision D-03: at most 3 active devices; a 4th is refused at sign-in.

const DEVICE_PASSWORD = 'password-for-tests-only';

function deviceToken(): string
{
    return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
}

function loginOnDevice(string $email, string $token): TestResponse
{
    return test()->withCookie(DeviceCookie::NAME, $token)->post('/login', ['email' => $email, 'password' => DEVICE_PASSWORD]);
}

function giveDevices(User $user, int $count): void
{
    foreach (range(1, $count) as $i) {
        UserDevice::query()->create([
            'user_id' => $user->id,
            'device_fingerprint_hash' => hash('sha256', Str::random()),
            'label' => "Test device {$i}",
            'last_seen_at' => now()->subDays($i),
        ]);
    }
}

beforeEach(function (): void {
    $this->audit = RecordingAuditLog::swap();
    $this->user = User::factory()->create(['email' => 'devices@example.test']);
});

it('registers the device at login and reuses it next time', function (): void {
    $token = deviceToken();

    loginOnDevice('devices@example.test', $token)->assertRedirect(route('account'))->assertCookie(DeviceCookie::NAME);
    $first = session(Devices::SESSION_KEY);

    auth()->logout();
    loginOnDevice('devices@example.test', $token);

    expect(session(Devices::SESSION_KEY))->toBe($first)
        ->and(UserDevice::query()->where('user_id', $this->user->id)->count())->toBe(1);

    // Only a keyed hash of the token is stored.
    expect(UserDevice::query()->sole()->device_fingerprint_hash)->not->toBe($token);
});

it('refuses a 4th device and shows the active ones', function (): void {
    giveDevices($this->user, 3);

    loginOnDevice('devices@example.test', deviceToken())->assertRedirect(route('login.devices'));

    $this->assertGuest();
    expect($this->audit->has('identity.device_refused', $this->user->id))->toBeTrue()
        ->and(UserDevice::query()->where('user_id', $this->user->id)->whereNull('revoked_at')->count())->toBe(3);

    $this->get('/login/devices')
        ->assertOk()
        ->assertSee('You are signed in on 3 devices')
        ->assertSee('Test device 1')
        ->assertSee('Test device 3');

    // Continuing without freeing a slot is still refused.
    $this->post('/login/devices/continue')->assertRedirect(route('login.devices'));
    $this->assertGuest();
});

it('lets the refused user sign out a device and continue (D-03)', function (): void {
    giveDevices($this->user, 3);
    $token = deviceToken();
    loginOnDevice('devices@example.test', $token);

    $oldest = UserDevice::query()->where('label', 'Test device 3')->sole();
    $this->delete('/login/devices/'.$oldest->id)->assertRedirect(route('login.devices'));

    $this->withCookie(DeviceCookie::NAME, $token)->post('/login/devices/continue')->assertRedirect(route('account'));

    $this->assertAuthenticatedAs($this->user);
    expect(UserDevice::query()->where('user_id', $this->user->id)->whereNull('revoked_at')->count())->toBe(3)
        ->and($oldest->refresh()->revoked_at)->not->toBeNull();
});

it('does not let the device-limit page touch another account\'s devices', function (): void {
    giveDevices($this->user, 3);
    $other = User::factory()->create();
    giveDevices($other, 1);
    $theirs = UserDevice::query()->where('user_id', $other->id)->sole();

    loginOnDevice('devices@example.test', deviceToken());
    $this->delete('/login/devices/'.$theirs->id);

    expect($theirs->refresh()->revoked_at)->toBeNull();
});

it('sends the device-limit page back to log in without a held login', function (): void {
    $this->get('/login/devices')->assertRedirect(route('login'));
    $this->post('/login/devices/continue')->assertRedirect(route('login'));
});

it('refuses a 4th device for passkey logins too', function (): void {
    $authenticator = VirtualAuthenticator::forApp();
    registerPasskey($this->user, $authenticator);
    auth()->logout();
    giveDevices($this->user, 2);

    $options = $this->withCookie(DeviceCookie::NAME, deviceToken())->postJson('/login/passkey/options')->json();

    $this->postJson('/login/passkey', $authenticator->assert($options))
        ->assertStatus(409)
        ->assertJson(['redirect' => route('login.devices')]);
    $this->assertGuest();
});

it('ends the session of a device that was signed out remotely', function (): void {
    loginOnDevice('devices@example.test', deviceToken());
    $this->get('/account')->assertOk();

    app(Devices::class)->revoke($this->user, (string) session(Devices::SESSION_KEY));

    $this->get('/account')
        ->assertRedirect(route('login'))
        ->assertSessionHas('status', 'You were signed out on this device.');
    $this->assertGuest();
});

it('signs out another of the user\'s devices from Devices & security', function (): void {
    Event::fake([DeviceRevoked::class]);
    loginOnDevice('devices@example.test', deviceToken());
    giveDevices($this->user, 1);
    $other = UserDevice::query()->where('label', 'Test device 1')->sole();

    $this->get('/account/security')
        ->assertSee('ACTIVE DEVICES')
        ->assertSee('2 of 3')
        ->assertSee('This device')
        ->assertSee('Test device 1');

    $this->delete('/account/devices/'.$other->id)->assertRedirect(route('account.security'));

    expect($other->refresh()->revoked_at)->not->toBeNull()
        ->and($this->audit->has('identity.device_revoked', $other->id))->toBeTrue();
    Event::assertDispatched(DeviceRevoked::class, fn (DeviceRevoked $e): bool => $e->deviceId === $other->id);
    $this->assertAuthenticatedAs($this->user);
});

it('forbids signing out someone else\'s device (SEC-06)', function (): void {
    $other = User::factory()->create();
    giveDevices($other, 1);
    $theirs = UserDevice::query()->where('user_id', $other->id)->sole();

    loginOnDevice('devices@example.test', deviceToken());

    $this->delete('/account/devices/'.$theirs->id)->assertForbidden();
    expect($theirs->refresh()->revoked_at)->toBeNull();
});

it('signs out of all other devices but keeps this one', function (): void {
    loginOnDevice('devices@example.test', deviceToken());
    $current = session(Devices::SESSION_KEY);
    giveDevices($this->user, 2);

    $this->post('/account/devices/sign-out-others')->assertRedirect(route('account.security'));

    expect(UserDevice::query()->where('user_id', $this->user->id)->whereNull('revoked_at')->pluck('id')->all())->toBe([$current]);
    $this->get('/account')->assertOk();
});

it('frees the device slot on log out', function (): void {
    loginOnDevice('devices@example.test', deviceToken());

    $this->post('/logout');

    expect(UserDevice::query()->where('user_id', $this->user->id)->whereNull('revoked_at')->count())->toBe(0);
});

it('shows the device count on the account hub', function (): void {
    loginOnDevice('devices@example.test', deviceToken());
    giveDevices($this->user, 1);

    $this->get('/account')->assertSee('2 devices');
});
