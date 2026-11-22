<?php

declare(strict_types=1);

namespace App\Modules\Identity\Contracts;

/**
 * Sends SMS such as phone one-time codes (provider not chosen).
 */
interface SmsSender
{
    /**
     * @param  string  $phoneE164  e.g. +254700000000
     */
    public function send(string $phoneE164, string $message): void;
}
