<?php

declare(strict_types=1);

namespace App\Modules\Identity\Gateways\Hibp;

use App\Modules\Identity\Contracts\BreachedPasswordChecker;
use Illuminate\Http\Client\Factory as Http;
use Throwable;

/**
 * Pwned Passwords range API (k-anonymity). Only the first five hex characters of the
 * SHA-1 hash leave the server; responses are padded so their size reveals nothing.
 *
 * If the service is unreachable the password is accepted (fail open) so sign-up keeps
 * working; the length rule still applies.
 */
final readonly class HibpBreachedPasswordChecker implements BreachedPasswordChecker
{
    public function __construct(
        private Http $http,
        private string $endpoint = 'https://api.pwnedpasswords.com/range/',
    ) {}

    public function isBreached(string $password): bool
    {
        $hash = strtoupper(sha1($password));
        $prefix = substr($hash, 0, 5);
        $suffix = substr($hash, 5);

        try {
            $response = $this->http
                ->withHeaders(['Add-Padding' => 'true'])
                ->timeout(3)
                ->get($this->endpoint.$prefix);
        } catch (Throwable) {
            return false;
        }

        if (! $response->successful()) {
            return false;
        }

        foreach (preg_split('/\r\n|\n/', $response->body()) ?: [] as $line) {
            [$candidate, $count] = array_pad(explode(':', trim($line), 2), 2, '0');
            if (hash_equals($suffix, $candidate) && (int) $count > 0) {
                return true;
            }
        }

        return false;
    }
}
