<?php

declare(strict_types=1);

namespace App\Modules\Identity\Contracts;

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
}
