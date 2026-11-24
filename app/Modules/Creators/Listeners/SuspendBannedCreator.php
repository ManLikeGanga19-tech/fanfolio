<?php

declare(strict_types=1);

namespace App\Modules\Creators\Listeners;

use App\Modules\Audit\Contracts\AuditLog;
use App\Modules\Creators\Enums\CreatorStatus;
use App\Modules\Creators\Events\CreatorSuspended;
use App\Modules\Creators\Models\Creator;
use App\Modules\TrustSafety\Events\UserBanned;
use Illuminate\Support\Facades\DB;

/**
 * Plan M8a use case 17: a banned account's creator page is suspended, so it stays shut
 * even if the ban is lifted, until staff reinstate it. Idempotent.
 */
final readonly class SuspendBannedCreator
{
    public function __construct(private AuditLog $audit) {}

    public function handle(UserBanned $event): void
    {
        DB::transaction(function () use ($event): void {
            $creator = Creator::query()->where('user_id', $event->userId)->lockForUpdate()->first();

            if (! $creator instanceof Creator || $creator->status === CreatorStatus::Suspended) {
                return;
            }

            $creator->forceFill(['status' => CreatorStatus::Suspended, 'suspended_at' => now()])->save();

            $this->audit->record('creator.suspended', $event->staffId, 'creator', $creator->id, ['reason' => 'account_banned']);

            CreatorSuspended::dispatch($creator->id, $creator->user_id, 'account_banned');
        });
    }
}
