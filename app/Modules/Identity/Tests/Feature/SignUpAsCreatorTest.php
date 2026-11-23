<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Notifications\EmailVerificationCode;
use Illuminate\Support\Facades\Notification;

// Canvas AuthSignup: "I'm a fan / I'm a creator". A creator goes from the email step to Become a creator.

beforeEach(function (): void {
    Notification::fake();
});

/** @param array<string, string> $overrides */
function signUpAs(array $overrides = []): User
{
    test()->post('/signup', [
        'email' => 'joining@example.test',
        'username' => 'joining_user',
        'password' => 'a long and unusual passphrase 42',
        'password_confirmation' => 'a long and unusual passphrase 42',
        'adult_and_terms' => '1',
        ...$overrides,
    ])->assertRedirect(route('signup.email'));

    return User::query()->where('email', 'joining@example.test')->sole();
}

function verifyFirstEmailCode(User $user): Illuminate\Testing\TestResponse
{
    $code = Notification::sent($user, EmailVerificationCode::class)->last()->code;

    return test()->post('/signup/email/verify', ['code' => $code]);
}

it('shows the fan or creator choice, with creator chosen from ?as=creator', function (): void {
    $this->get('/signup')->assertOk()->assertSee("I'm a fan")->assertSee("I'm a creator")
        ->assertSee('value="fan" class="sr-only" checked', false);

    $this->get('/signup?as=creator')->assertSee('value="creator" class="sr-only" checked', false);
});

it('sends a new creator from the email step to Become a creator', function (): void {
    $user = signUpAs(['joining_as' => 'creator']);

    verifyFirstEmailCode($user)->assertRedirect(route('creator.start'));
});

it('sends a new fan from the email step to the 18+ check', function (): void {
    $user = signUpAs(['joining_as' => 'fan']);

    verifyFirstEmailCode($user)->assertRedirect(route('signup.age'));
});

it('treats a missing choice as a fan and refuses an unknown one', function (): void {
    $this->post('/signup', ['joining_as' => 'admin'])->assertSessionHasErrors('joining_as');

    $user = signUpAs();
    verifyFirstEmailCode($user)->assertRedirect(route('signup.age'));
});
