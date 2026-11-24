<?php

declare(strict_types=1);

namespace App\Modules\Creators\Contracts;

/**
 * Public creator pages by handle, for other modules (for example Trust & Safety, when a
 * fan reports a creator page). Handles are public; nothing else is shared.
 */
interface CreatorPages
{
    /** The account behind an approved or suspended page, or null when there is none. */
    public function userIdForHandle(string $handle): ?string;

    /** The page's handle for an account, without the @, or null when it has none. */
    public function handleForUser(string $userId): ?string;
}
