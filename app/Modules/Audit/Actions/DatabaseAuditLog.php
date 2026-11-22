<?php

declare(strict_types=1);

namespace App\Modules\Audit\Actions;

use App\Modules\Audit\Contracts\AuditLog;
use App\Modules\Audit\Models\AuditEntry;
use Illuminate\Http\Request;

final readonly class DatabaseAuditLog implements AuditLog
{
    public function __construct(private Request $request) {}

    public function record(
        string $action,
        ?string $actorId,
        ?string $subjectType = null,
        ?string $subjectId = null,
        array $context = [],
    ): void {
        AuditEntry::query()->create([
            'actor_id' => $actorId,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'context' => $context,
            'ip' => $this->request->ip(),
            'user_agent' => $this->request->userAgent(),
            'occurred_at' => now(),
        ]);
    }
}
