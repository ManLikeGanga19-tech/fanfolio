<?php

declare(strict_types=1);

namespace App\Modules\Creators\Contracts;

use App\Modules\Creators\Contracts\Data\ApplicationStage;

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

    /**
     * Where an account's creator application stands, for the account hub: null when the
     * account never started one, or once it is decided (approved or suspended).
     */
    public function applicationStage(string $userId): ?ApplicationStage;
}
