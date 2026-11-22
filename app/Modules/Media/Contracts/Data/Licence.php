<?php

declare(strict_types=1);

namespace App\Modules\Media\Contracts\Data;

final readonly class Licence
{
    public function __construct(
        public string $body,
        public string $contentType,
    ) {}
}
