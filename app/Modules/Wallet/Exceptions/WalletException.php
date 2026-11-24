<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Exceptions;

use RuntimeException;

/** A wallet rule refused the request; the message is safe to show and names the form field. */
final class WalletException extends RuntimeException
{
    public function __construct(string $message, public readonly string $field)
    {
        parent::__construct($message);
    }
}
