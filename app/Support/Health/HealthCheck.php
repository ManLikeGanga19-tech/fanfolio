<?php

declare(strict_types=1);

namespace App\Support\Health;

/** One line of the admin Overview "System health" card. */
final readonly class HealthCheck
{
    public const string OK = 'ok';

    public const string WARNING = 'warning';

    public const string PROBLEM = 'problem';

    /**
     * @param  self::OK|self::WARNING|self::PROBLEM  $level
     */
    public function __construct(
        public string $title,
        public string $detail,
        public string $level,
    ) {}
}
