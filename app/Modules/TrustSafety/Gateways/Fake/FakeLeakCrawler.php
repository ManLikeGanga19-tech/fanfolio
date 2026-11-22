<?php

declare(strict_types=1);

namespace App\Modules\TrustSafety\Gateways\Fake;

use App\Modules\TrustSafety\Contracts\Data\LeakSighting;
use App\Modules\TrustSafety\Contracts\LeakCrawler;
use App\Support\Gateways\FakeDriver;
use DateTimeImmutable;

final class FakeLeakCrawler implements FakeDriver, LeakCrawler
{
    /** @var list<LeakSighting> */
    private array $sightings = [];

    public function addSighting(LeakSighting $sighting): self
    {
        $this->sightings[] = $sighting;

        return $this;
    }

    public function sightingsSince(DateTimeImmutable $since): array
    {
        return array_values(array_filter(
            $this->sightings,
            static fn (LeakSighting $s): bool => $s->foundAt >= $since,
        ));
    }
}
