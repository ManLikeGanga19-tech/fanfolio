<?php

declare(strict_types=1);

use App\Modules\Identity\Enums\Role;
use App\Modules\Identity\Events\UserRegistered;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Notifications\EmailVerificationCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Tests\Support\RecordingAuditLog;

function signUpData(array $overrides = []): array
{
    $data = [
        'email' => 'new-fan@example.test',
        'username' => 'new_fan',
        'password' => 'a-long-unique-passphrase',
        'adult_and_terms' => '1',
        ...$overrides,
    ];

    // The confirmation matches unless a test sets it.
    $data['password_confirmation'] ??= $data['password'];

    return $data;
}

it('shows step 1 of sign up', function (): void {
    $this->get('/signup')
        ->assertOk()
        ->assertSee('STEP 1 OF 3 · ACCOUNT')
        ->assertSee('Create your account')
        ->assertSee('Creators see this instead of your real name')
        ->assertSee('At least 12 characters');
});

it('registers a fan, signs them in, emails a code and moves to the email step', function (): void {
    $audit = RecordingAuditLog::swap();
    Notification::fake();

    $this->post('/signup', signUpData())->assertRedirect(route('signup.email'));

    $user = User::query()->where('email', 'new-fan@example.test')->sole();

    expect($user->role)->toBe(Role::Fan)
        ->and($user->mfa_enabled)->toBeFalse()
        ->and(password_get_info($user->password)['algoName'])->toBe('argon2id');

    $this->assertAuthenticatedAs($user);
    expect($audit->has('identity.registered', $user->id))->toBeTrue()
        ->and($user->hasVerifiedEmail())->toBeFalse();
    Notification::assertSentTo($user, EmailVerificationCode::class);
});

it('dispatches UserRegistered only after the transaction commits', function (): void {
    Event::fake([UserRegistered::class]);

    DB::transaction(function (): void {
        $this->post('/signup', signUpData());
        Event::assertNotDispatched(UserRegistered::class);
    });

    Event::assertDispatched(UserRegistered::class);
});

it('rejects passwords shorter than 12 characters', function (): void {
    $this->post('/signup', signUpData(['password' => 'short-pass1']))
        ->assertSessionHasErrors(['password' => 'At least 12 characters.']);

    $this->assertGuest();
});

it('rejects breached passwords (SEC-03)', function (): void {
    $this->post('/signup', signUpData(['password' => 'password1234']))
        ->assertSessionHasErrors('password');

    expect(User::query()->count())->toBe(0);
});

it('treats emails and usernames as case-insensitive', function (): void {
    User::factory()->create(['email' => 'Taken@Example.test', 'username' => 'Taken_Name']);

    $this->post('/signup', signUpData(['email' => 'taken@example.TEST']))
        ->assertSessionHasErrors(['email' => 'This email already has an account. Log in instead.']);

    $this->post('/signup', signUpData(['username' => 'taken_name']))
        ->assertSessionHasErrors(['username' => 'This username is taken.']);
});

it('requires the 18+ and terms confirmation', function (): void {
    $this->post('/signup', signUpData(['adult_and_terms' => null]))
        ->assertSessionHasErrors('adult_and_terms');
});

it('strips the @ from usernames and rejects other symbols', function (): void {
    $this->post('/signup', signUpData(['username' => '@kevo_254']));
    expect(User::query()->where('username', 'kevo_254')->exists())->toBeTrue();

    auth()->logout();

    $this->post('/signup', signUpData(['email' => 'other@example.test', 'username' => 'bad name!']))
        ->assertSessionHasErrors('username');
});

it('never lets a sign-up choose its own role or flags', function (): void {
    $this->post('/signup', signUpData(['role' => 'admin', 'mfa_enabled' => true, 'status' => 'active', 'age_verified_at' => now()->toIso8601String()]));

    $user = User::query()->sole();

    expect($user->role)->toBe(Role::Fan)
        ->and($user->mfa_enabled)->toBeFalse()
        ->and($user->age_verified_at)->toBeNull();
});

it('sends signed-in users away from sign up', function (): void {
    $this->actingAs(User::factory()->create())->get('/signup')->assertRedirect(route('account'));
});

it('asks for the password twice', function (): void {
    $this->post('/signup', signUpData(['password_confirmation' => 'a-different-passphrase']))
        ->assertSessionHasErrors(['password' => 'The two passwords do not match.']);

    expect(User::query()->count())->toBe(0);
    $this->assertGuest();
});

it('gives every password field a show or hide button', function (): void {
    $this->get('/signup')
        ->assertSee('data-password-toggle="field-password"', false)
        ->assertSee('data-password-toggle="field-password_confirmation"', false)
        ->assertSee('aria-label="Show confirm password"', false);

    $this->get('/login')->assertSee('aria-label="Show password"', false);
});
