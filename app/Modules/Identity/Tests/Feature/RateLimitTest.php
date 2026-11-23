<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;

// SEC-14: rate limits answer 429 with Retry-After.

it('limits login attempts per email and address', function (): void {
    foreach (range(1, 5) as $attempt) {
        $this->post('/login', ['email' => 'target@example.test', 'password' => 'wrong-password-'.$attempt])->assertStatus(302);
    }

    $this->post('/login', ['email' => 'target@example.test', 'password' => 'wrong-password-6'])
        ->assertStatus(429)
        ->assertHeader('Retry-After');
});

it('limits phone code checks per account', function (): void {
    $this->actingAs(User::factory()->create());

    foreach (range(1, 10) as $attempt) {
        $this->post('/signup/phone/verify', ['code' => '000000'])->assertStatus(302);
    }

    $this->post('/signup/phone/verify', ['code' => '000000'])
        ->assertStatus(429)
        ->assertHeader('Retry-After');
});

it('limits phone code sends per account', function (): void {
    $this->actingAs(User::factory()->create());

    foreach (range(1, 5) as $attempt) {
        $this->post('/signup/phone/send', ['phone' => '+25470000041'.$attempt])->assertStatus(302);
        $this->travel(61)->seconds();
    }

    $this->post('/signup/phone/send', ['phone' => '+254700000419'])
        ->assertStatus(429)
        ->assertHeader('Retry-After');
});

it('limits sign-ups per address', function (): void {
    foreach (range(1, 10) as $attempt) {
        $this->post('/signup', ['email' => 'not-an-email'])->assertStatus(302);
    }

    $this->post('/signup', ['email' => 'not-an-email'])->assertStatus(429);
});
