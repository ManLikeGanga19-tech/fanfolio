<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\Notification;

// SEC-14: rate limits answer 429 with Retry-After.

it('limits login attempts per email and address', function (): void {
    foreach (range(1, 5) as $attempt) {
        $this->post('/login', ['email' => 'target@example.test', 'password' => 'wrong-password-'.$attempt])->assertStatus(302);
    }

    $this->post('/login', ['email' => 'target@example.test', 'password' => 'wrong-password-6'])
        ->assertStatus(429)
        ->assertHeader('Retry-After');
});

it('limits email code checks per account', function (): void {
    $this->actingAs(User::factory()->create(['email_verified_at' => null]));

    foreach (range(1, 10) as $attempt) {
        $this->post('/signup/email/verify', ['code' => '000000'])->assertStatus(302);
    }

    $this->post('/signup/email/verify', ['code' => '000000'])
        ->assertStatus(429)
        ->assertHeader('Retry-After');
});

it('limits email code sends per account', function (): void {
    Notification::fake();
    $this->actingAs(User::factory()->create(['email_verified_at' => null]));

    foreach (range(1, 5) as $attempt) {
        $this->post('/signup/email/send')->assertStatus(302);
        $this->travel(61)->seconds();
    }

    $this->post('/signup/email/send')
        ->assertStatus(429)
        ->assertHeader('Retry-After');
});

it('limits sign-ups per address', function (): void {
    foreach (range(1, 10) as $attempt) {
        $this->post('/signup', ['email' => 'not-an-email'])->assertStatus(302);
    }

    $this->post('/signup', ['email' => 'not-an-email'])->assertStatus(429);
});
