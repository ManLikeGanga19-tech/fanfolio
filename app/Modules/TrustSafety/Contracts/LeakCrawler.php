<?php

declare(strict_types=1);

namespace App\Modules\TrustSafety\Contracts;

use App\Modules\TrustSafety\Contracts\Data\LeakSighting;
use DateTimeImmutable;

/**
 * Crawls known leak sites and channels and reads the watermark on each copy found.
 */
interface LeakCrawler
{
    /**
     * @return list<LeakSighting>
     */
    public function sightingsSince(DateTimeImmutable $since): array;
}
