<?php

declare(strict_types=1);

namespace App\Modules\Creators\Actions;

use App\Modules\Audit\Contracts\AuditLog;
use App\Modules\Creators\Events\CreatorApplied;
use App\Modules\Creators\Exceptions\CreatorException;
use App\Modules\Creators\Models\Creator;
use App\Modules\Identity\Contracts\Accounts;
use Illuminate\Support\Facades\DB;

/**
 * "Start verification" on Become a creator. Creators must have MFA (SEC-04), so the
 * account needs a passkey or an authenticator app first. Starting twice is harmless.
 */
final readonly class StartCreatorApplication
{
    public function __construct(
        private Accounts $accounts,
        private AuditLog $audit,
    ) {}

    public function handle(string $userId): Creator
    {
        if (! $this->accounts->hasMfa($userId)) {
            throw new CreatorException('Creators need a passkey or an authenticator app. Set one up, then start again.', 'mfa');
        }

        return DB::transaction(function () use ($userId): Creator {
            $existing = Creator::query()->where('user_id', $userId)->lockForUpdate()->first();

            if ($existing instanceof Creator) {
                return $existing;
            }

            $creator = Creator::query()->create(['user_id' => $userId]);

            $this->audit->record('creator.application_started', $userId, 'creator', $creator->id);
            CreatorApplied::dispatch($creator->id, $userId);

            return $creator;
        });
    }
}
