<?php

declare(strict_types=1);

namespace App\Modules\Identity\Contracts\Data;

/** What a computer shows as a QR code (and as a link to type) for the phone. */
final readonly class HandoffLink
{
    public function __construct(
        public string $url,
        public int $expiresAt,
    ) {}

    /**
     * @return array{url: string, expires_at: int}
     */
    public function toArray(): array
    {
        return ['url' => $this->url, 'expires_at' => $this->expiresAt];
    }

    /**
     * Back from the session; null when missing, malformed or expired.
     */
    public static function fromSession(mixed $value): ?self
    {
        if (! is_array($value) || ! is_string($value['url'] ?? null) || ! is_int($value['expires_at'] ?? null)) {
            return null;
        }

        return $value['expires_at'] > now()->getTimestamp() ? new self($value['url'], $value['expires_at']) : null;
    }
}
