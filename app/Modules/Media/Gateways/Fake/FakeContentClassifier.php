<?php

declare(strict_types=1);

namespace App\Modules\Media\Gateways\Fake;

use App\Modules\Media\Contracts\ContentClassifier;
use App\Modules\Media\Contracts\Data\Classification;
use App\Modules\Media\Contracts\Data\ClassifierVerdict;
use App\Support\Gateways\FakeDriver;

/**
 * Allows everything unless a result was scripted for the object.
 */
final class FakeContentClassifier implements ContentClassifier, FakeDriver
{
    /** @var array<string, Classification> */
    private array $scripted = [];

    public function willReturn(string $objectKey, Classification $classification): self
    {
        $this->scripted[$objectKey] = $classification;

        return $this;
    }

    public function classify(string $objectKey): Classification
    {
        return $this->scripted[$objectKey] ?? new Classification(ClassifierVerdict::Allow, 0, 0);
    }
}
