<?php

declare(strict_types=1);

namespace App\Modules\Identity\Exceptions;

use RuntimeException;

/**
 * A refusal the user can act on. The message is safe to show in the UI;
 * the field names the form input it belongs to.
 */
final class IdentityException extends RuntimeException
{
    public function __construct(string $message, public readonly string $field)
    {
        parent::__construct($message);
    }
}
