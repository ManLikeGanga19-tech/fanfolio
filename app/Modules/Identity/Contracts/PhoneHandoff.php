<?php

declare(strict_types=1);

namespace App\Modules\Identity\Contracts;

use App\Modules\Identity\Contracts\Data\HandoffLink;

/**
 * Decision D-14: the ID and face scan run on a phone, never a computer's webcam. On a
 * computer the screen shows a QR code; the phone opens the provider's capture with it
 * and is told to go back to the computer when done.
 *
 * Used by Creators (creator KYC) and by Identity itself (the fan 18+ check).
 */
interface PhoneHandoff
{
    /**
     * The return URL to give the provider for a session finished on a phone. The phone
     * (not signed in) gets "All done, go back to your computer"; the same browser, signed
     * in as $userId, is sent on to $next with the provider's ref.
     */
    public function returnUrl(string $userId, string $next): string;

    /**
     * A link for the phone to open the provider's capture page. It works once and
     * expires after 10 minutes. The token is stored only as a hash.
     */
    public function issue(string $userId, string $providerUrl): HandoffLink;
}
