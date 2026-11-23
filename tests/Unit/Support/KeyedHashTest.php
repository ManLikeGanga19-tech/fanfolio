<?php

declare(strict_types=1);

use App\Support\Security\KeyedHash;

it('is deterministic per purpose and differs across purposes', function (): void {
    $hash = new KeyedHash('base64:test-key-only');

    expect($hash->make('phone', '+254700000000'))->toBe($hash->make('phone', '+254700000000'))
        ->and($hash->make('phone', '+254700000000'))->not->toBe($hash->make('code', '+254700000000'))
        ->and($hash->matches('phone', '+254700000000', $hash->make('phone', '+254700000000')))->toBeTrue()
        ->and($hash->matches('phone', '+254700000001', $hash->make('phone', '+254700000000')))->toBeFalse();
});

it('changes with the application key', function (): void {
    expect(new KeyedHash('key-one')->make('phone', 'x'))->not->toBe(new KeyedHash('key-two')->make('phone', 'x'));
});

it('refuses an empty key', function (): void {
    new KeyedHash('');
})->throws(RuntimeException::class);
