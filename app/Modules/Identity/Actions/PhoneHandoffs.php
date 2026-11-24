<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Audit\Contracts\AuditLog;
use App\Modules\Identity\Contracts\Data\HandoffLink;
use App\Modules\Identity\Contracts\PhoneHandoff;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\URL;

/**
 * Decision D-14. Tokens are random, stored only as a SHA-256 hash in the cache
 * (Redis in production), single use, and live 10 minutes. Opening one is audited.
 */
final readonly class PhoneHandoffs implements PhoneHandoff
{
    public const int TTL_SECONDS = 600;

    public function __construct(
        private Cache $cache,
        private AuditLog $audit,
    ) {}

    public function returnUrl(string $userId, string $next): string
    {
        // Signed so "next" cannot be used as an open redirect; the provider's ref is added after signing.
        return URL::temporarySignedRoute('handoff.done', now()->addDay(), ['user' => $userId, 'next' => $next]);
    }

    public function issue(string $userId, string $providerUrl): HandoffLink
    {
        $token = bin2hex(random_bytes(24));
        $expiresAt = now()->addSeconds(self::TTL_SECONDS);

        $this->cache->put($this->key($token), ['user' => $userId, 'url' => $providerUrl], $expiresAt);
        $this->audit->record('identity.phone_handoff_issued', $userId, 'user', $userId);

        return new HandoffLink(route('handoff.open', ['token' => $token]), $expiresAt->getTimestamp());
    }

    /**
     * The phone opened the link: the provider URL, once. Null when unknown, used or expired.
     */
    public function open(string $token): ?string
    {
        if (preg_match('/^[0-9a-f]{48}$/', $token) !== 1) {
            return null;
        }

        $entry = $this->cache->pull($this->key($token));

        if (! is_array($entry) || ! is_string($entry['url'] ?? null) || ! is_string($entry['user'] ?? null)) {
            return null;
        }

        $this->audit->record('identity.phone_handoff_opened', $entry['user'], 'user', $entry['user']);

        return $entry['url'];
    }

    private function key(string $token): string
    {
        return 'phone-handoff:'.hash('sha256', $token);
    }
}
