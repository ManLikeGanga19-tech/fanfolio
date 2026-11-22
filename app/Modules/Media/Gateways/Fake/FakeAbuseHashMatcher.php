<?php

declare(strict_types=1);

namespace App\Modules\Media\Gateways\Fake;

use App\Modules\Media\Contracts\AbuseHashMatcher;
use App\Modules\Media\Contracts\Data\HashMatch;
use App\Support\Gateways\FakeDriver;

/**
 * Matches only hashes registered with addKnownHash(). Tests use known-safe hashes (SEC-09).
 */
final class FakeAbuseHashMatcher implements AbuseHashMatcher, FakeDriver
{
    /** @var array<string, true> */
    private array $known = [];

    public function addKnownHash(string $hash): self
    {
        $this->known[strtolower($hash)] = true;

        return $this;
    }

    public function match(string $sha256, string $perceptualHash): HashMatch
    {
        foreach ([$sha256, $perceptualHash] as $hash) {
            if (isset($this->known[strtolower($hash)])) {
                return new HashMatch(true, 'fake-test-list', 'fake_match_'.substr(strtolower($hash), 0, 12));
            }
        }

        return new HashMatch(false);
    }
}
