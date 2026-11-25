<?php

declare(strict_types=1);

namespace App\Modules\Audit\Actions;

use App\Modules\Audit\Contracts\AuditHistory;
use App\Modules\Audit\Models\AuditEntry;
use DateTimeInterface;

final readonly class DatabaseAuditHistory implements AuditHistory
{
    public function countFromIp(string $action, string $actorId, string $ip, DateTimeInterface $before): int
    {
        return AuditEntry::query()
            ->where('action', $action)
            ->where('actor_id', $actorId)
            ->where('ip', $ip)
            ->where('occurred_at', '<', $before)
            ->count();
    }
}
