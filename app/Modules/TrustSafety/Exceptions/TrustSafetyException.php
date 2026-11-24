<?php

declare(strict_types=1);

namespace App\Modules\TrustSafety\Exceptions;

use RuntimeException;

/** A rule refused the decision; the message is safe to show and names the form field. */
final class TrustSafetyException extends RuntimeException
{
    public function __construct(string $message, public readonly string $field)
    {
        parent::__construct($message);
    }
}
