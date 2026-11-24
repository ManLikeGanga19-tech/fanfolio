<?php

declare(strict_types=1);

use App\Modules\Identity\Enums\AccountStatus;
use App\Modules\Identity\Models\User;
use Tests\Support\RecordingAuditLog;

beforeEach(function (): void {
    $this->audit = RecordingAuditLog::swap();
});

const LOGIN_PASSWORD = 'password-for-tests-only';

it('shows the log in screen', function (): void {
    $this->get('/login')->assertOk()->assertSee('Welcome back')->assertSee('Create an account');
});

it('logs in with email and password and records it', function (): void {
    $user = User::factory()->create(['email' => 'fan@example.test']);

    $this->post('/login', ['email' => 'FAN@example.test', 'password' => LOGIN_PASSWORD])
        ->assertRedirect(route('account'));

    $this->assertAuthenticatedAs($user);
    expect($user->refresh()->last_login_at)->not->toBeNull()
        ->and($this->audit->has('identity.login', $user->id))->toBeTrue();
});

it('gives the same answer for a wrong password and an unknown email', function (): void {
    User::factory()->create(['email' => 'fan@example.test']);

    $this->post('/login', ['email' => 'fan@example.test', 'password' => 'not-the-password'])
        ->assertSessionHasErrors(['email' => 'Email or password is not right.']);
    $this->post('/login', ['email' => 'nobody@example.test', 'password' => 'not-the-password'])
        ->assertSessionHasErrors(['email' => 'Email or password is not right.']);

    $this->assertGuest();
});

it('records failed attempts against the account', function (): void {
    $user = User::factory()->create(['email' => 'fan@example.test']);

    $this->post('/login', ['email' => 'fan@example.test', 'password' => 'not-the-password']);

    expect($this->audit->has('identity.login_failed', $user->id))->toBeTrue();
});

it('refuses suspended and banned accounts', function (AccountStatus $status): void {
    User::factory()->withStatus($status)->create(['email' => 'fan@example.test']);

    $this->post('/login', ['email' => 'fan@example.test', 'password' => LOGIN_PASSWORD])
        ->assertSessionHasErrors(['email' => 'This account cannot sign in. Contact support.']);

    $this->assertGuest();
})->with([AccountStatus::Suspended, AccountStatus::Banned]);

it('starts a new session id on login', function (): void {
    User::factory()->create(['email' => 'fan@example.test']);

    $this->get('/login');
    $before = session()->getId();

    $this->post('/login', ['email' => 'fan@example.test', 'password' => LOGIN_PASSWORD]);

    expect(session()->getId())->not->toBe($before);
});

it('logs out and records it', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/logout')->assertRedirect(route('landing'));

    $this->assertGuest();
    expect($this->audit->has('identity.logout', $user->id))->toBeTrue();
});

it('shows the landing page to guests and the account hub to members', function (): void {
    $this->get('/')->assertOk()->assertSee('Your fans. Your content.')->assertSee('Join as a fan');

    $user = User::factory()->create(['username' => 'hub_fan']);
    $this->actingAs($user)->get('/')->assertRedirect(route('account'));
    $this->get('/account')->assertOk()->assertSee('@hub_fan')->assertSee('Log out');
});

it('sends guests to log in from the account hub', function (): void {
    $this->get('/account')->assertRedirect(route('login'));
});
