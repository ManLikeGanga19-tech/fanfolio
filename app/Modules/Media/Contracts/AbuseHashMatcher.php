<?php

declare(strict_types=1);

namespace App\Modules\Media\Contracts;

use App\Modules\Media\Contracts\Data\HashMatch;

/**
 * Matches uploads against known child-abuse hash lists (SEC-09).
 * A match quarantines the asset: it is never processed and never shown to moderators.
 */
interface AbuseHashMatcher
{
    public function match(string $sha256, string $perceptualHash): HashMatch;
}
