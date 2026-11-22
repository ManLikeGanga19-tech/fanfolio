<?php

declare(strict_types=1);

namespace App\Modules\TrustSafety\Contracts\Data;

use DateTimeImmutable;

final readonly class LeakSighting
{
    /**
     * @param  string|null  $watermarkCode  null when the visible mark was cropped and the forensic check is still running
     */
    public function __construct(
        public string $url,
        public string $siteKind,
        public DateTimeImmutable $foundAt,
        public ?string $watermarkCode,
    ) {}
}
