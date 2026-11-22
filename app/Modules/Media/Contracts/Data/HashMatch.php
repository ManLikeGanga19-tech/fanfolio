<?php

declare(strict_types=1);

namespace App\Modules\Media\Contracts\Data;

final readonly class HashMatch
{
    public function __construct(
        public bool $matched,
        public ?string $list = null,
        public ?string $matchRef = null,
    ) {}
}
