<?php

declare(strict_types=1);

namespace App\Modules\Identity\Contracts\Data;

final readonly class DocumentImage
{
    public function __construct(
        public string $contentType,
        public string $bytes,
    ) {}
}
