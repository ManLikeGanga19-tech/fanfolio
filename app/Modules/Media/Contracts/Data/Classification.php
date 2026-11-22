<?php

declare(strict_types=1);

namespace App\Modules\Media\Contracts\Data;

/**
 * Classifier scores in basis points (0 to 10_000), not floats.
 */
final readonly class Classification
{
    public function __construct(
        public ClassifierVerdict $verdict,
        public int $apparentMinorBps,
        public int $nudityBps,
    ) {}
}
