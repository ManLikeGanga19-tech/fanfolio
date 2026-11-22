<?php

declare(strict_types=1);

namespace App\Modules\Media\Contracts\Data;

final readonly class ScanVerdict
{
    public function __construct(
        public bool $clean,
        public ?string $signature = null,
    ) {}
}
