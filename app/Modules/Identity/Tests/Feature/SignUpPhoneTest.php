<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Support\VirtualAuthenticator;

// Decision D-12: sign-up takes an optional phone number. It is stored encrypted and
// unverified; the verified email stays the primary contact.

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function phoneSignUpData(array $overrides = []): array
{
    return [
        'email' => 'phone-fan@example.test',
        'username' => 'phone_fan',
        'password' => 'a-long-unique-passphrase',
        'password_confirmation' => 'a-long-unique-passphrase',
        'adult_and_terms' => '1',
        ...$overrides,
    ];
}

beforeEach(function (): void {
    Notification::fake();
});

it('shows the optional phone field on step 1', function (): void {
    $this->get('/signup')->assertOk()->assertSee('Phone number (optional)')->assertSee('we contact you by email');
});

it('stores the phone number encrypted and unverified, with the email still the one to verify', function (): void {
    $this->post('/signup', phoneSignUpData(['phone' => '+254 700-000 412']))->assertRedirect(route('signup.email'));

    $user = User::query()->sole();
    $row = DB::table('users')->where('id', $user->id)->first();

    expect($user->phone_e164)->toBe('+254700000412')
        ->and($user->phone_verified_at)->toBeNull()
        ->and($user->hasVerifiedEmail())->toBeFalse()
        // Rule 11: unreadable in the database.
        ->and($row->phone_e164)->not->toContain('700000412')
        // No keyed hash until verified, so an unverified number never blocks its owner.
        ->and($row->phone_hash)->toBeNull();
});

it('leaves the phone empty when it is not given', function (?string $phone): void {
    $this->post('/signup', phoneSignUpData(['phone' => $phone]))->assertRedirect(route('signup.email'));

    expect(User::query()->sole()->phone_e164)->toBeNull();
})->with([null, '', '   ']);

it('refuses a number without a country code', function (string $phone): void {
    $this->from('/signup')->post('/signup', phoneSignUpData(['phone' => $phone]))
        ->assertRedirect('/signup')
        ->assertSessionHasErrors(['phone' => 'Enter the number with its country code, for example +254.']);

    expect(User::query()->count())->toBe(0);
})->with(['0700000412', '+0700000412', 'not a number', '+2547000004120000']);

it('lets two accounts give the same unverified number', function (): void {
    $this->post('/signup', phoneSignUpData(['phone' => '+254700000412']));
    auth()->logout();

    $this->post('/signup', phoneSignUpData(['email' => 'second@example.test', 'username' => 'second_fan', 'phone' => '+254700000412']))
        ->assertRedirect(route('signup.email'));

    expect(User::query()->count())->toBe(2);
});

it('keeps the phone number through passkey sign-up', function (): void {
    $authenticator = VirtualAuthenticator::forApp();

    $options = $this->postJson('/signup/passkey/options', [
        'email' => 'passkey.fan@example.test',
        'username' => 'passkey_fan',
        'phone' => '+254 700 000 413',
        'adult_and_terms' => '1',
    ])->assertOk()->json();

    $this->postJson('/signup/passkey', $authenticator->attest($options))->assertOk();

    $user = User::query()->sole();
    expect($user->phone_e164)->toBe('+254700000413')
        ->and($user->phone_verified_at)->toBeNull();
});
