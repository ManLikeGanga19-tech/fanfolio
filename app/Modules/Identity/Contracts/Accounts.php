<?php

declare(strict_types=1);

namespace App\Modules\Identity\Contracts;

use Illuminate\Notifications\Notification;

/**
 * What other modules may ask Identity about an account, by user id. Other modules never
 * read the users table or use the User model.
 */
interface Accounts
{
    /** A passkey or a confirmed authenticator app (SEC-04). */
    public function hasMfa(string $userId): bool;

    /** The handle shown to creators and fans, without the @. */
    public function username(string $userId): ?string;

    /**
     * Send a notification to the account (email and so on) without handing its contact
     * details to the calling module. Does nothing for an unknown id.
     */
    public function notify(string $userId, Notification $notification): void;
}
