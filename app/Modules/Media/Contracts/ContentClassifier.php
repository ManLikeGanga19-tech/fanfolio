<?php

declare(strict_types=1);

namespace App\Modules\Media\Contracts;

use App\Modules\Media\Contracts\Data\Classification;

/**
 * AI classifier for apparent minors and nudity. Unclear results go to human review.
 */
interface ContentClassifier
{
    public function classify(string $objectKey): Classification;
}
