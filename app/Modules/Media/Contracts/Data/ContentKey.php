<?php

declare(strict_types=1);

namespace App\Modules\Media\Contracts\Data;

use SensitiveParameter;

final readonly class ContentKey
{
    /**
     * @param  string  $keyId  32 hex characters; the only part we store
     * @param  string  $keyHex  32 hex characters; in memory for the packager only, never stored or logged
     */
    public function __construct(
        public string $keyId,
        #[SensitiveParameter] public string $keyHex,
    ) {}
}
