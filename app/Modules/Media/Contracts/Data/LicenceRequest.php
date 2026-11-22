<?php

declare(strict_types=1);

namespace App\Modules\Media\Contracts\Data;

final readonly class LicenceRequest
{
    /**
     * @param  non-empty-list<string>  $keyIds
     * @param  string  $challenge  raw licence challenge from the CDM
     */
    public function __construct(
        public DrmSystem $system,
        public array $keyIds,
        public string $challenge,
        public string $playbackSessionId,
        public int $durationSeconds,
        public bool $requireHdcp,
    ) {}
}
