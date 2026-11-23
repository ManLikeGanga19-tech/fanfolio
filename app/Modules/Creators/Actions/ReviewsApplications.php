<?php

declare(strict_types=1);

namespace App\Modules\Creators\Actions;

use App\Modules\Creators\Exceptions\CreatorException;
use App\Modules\Creators\Models\Creator;
use App\Modules\Identity\Contracts\Accounts;
use Illuminate\Support\Facades\DB;

/**
 * Shared by the staff decisions (decision D-10). Each one runs in a transaction on a
 * locked row, checks the actor inside the Action (not only in the panel), and refuses
 * staff deciding on their own application.
 */
trait ReviewsApplications
{
    /**
     * @template T
     *
     * @param  callable(Creator): T  $decide
     * @return T
     */
    private function decide(Accounts $accounts, string $creatorId, string $staffId, callable $decide): mixed
    {
        if (! $accounts->isStaff($staffId)) {
            throw new CreatorException('Only staff with MFA can review creators.', 'staff');
        }

        return DB::transaction(function () use ($creatorId, $staffId, $decide): mixed {
            $creator = Creator::query()->whereKey($creatorId)->lockForUpdate()->firstOrFail();

            if ($creator->user_id === $staffId) {
                throw new CreatorException('You cannot review your own application.', 'staff');
            }

            return $decide($creator);
        });
    }
}
