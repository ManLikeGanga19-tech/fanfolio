<?php

declare(strict_types=1);

namespace App\Support\Security;

use RuntimeException;

/**
 * Deterministic keyed hash (HMAC-SHA256) for values that must be unique or searchable
 * but are stored encrypted, such as phone numbers and one-time codes. Each purpose gets
 * its own key derived from the application key, so hashes never match across uses.
 */
final readonly class KeyedHash
{
    public function __construct(private string $appKey)
    {
        if ($appKey === '') {
            throw new RuntimeException('APP_KEY is not set.');
        }
    }

    public static function fromConfig(): self
    {
        return new self(config()->string('app.key'));
    }

    public function make(string $purpose, string $value): string
    {
        $key = hash_hmac('sha256', 'fanfolio:'.$purpose, $this->appKey, true);

        return hash_hmac('sha256', $value, $key);
    }

    public function matches(string $purpose, string $value, string $hash): bool
    {
        return hash_equals($hash, $this->make($purpose, $value));
    }
}
