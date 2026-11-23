<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Audit\Contracts\AuditLog;

/**
 * Test double for the Audit contract, so other modules' tests can check what they
 * recorded without reading the Audit module's table.
 */
final class RecordingAuditLog implements AuditLog
{
    /** @var list<array{action: string, actor: ?string, subject_type: ?string, subject_id: ?string, context: array<string, mixed>}> */
    public array $entries = [];

    public static function swap(): self
    {
        $log = new self;
        app()->instance(AuditLog::class, $log);

        return $log;
    }

    public function record(string $action, ?string $actorId, ?string $subjectType = null, ?string $subjectId = null, array $context = []): void
    {
        $this->entries[] = [
            'action' => $action,
            'actor' => $actorId,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'context' => $context,
        ];
    }

    /**
     * @return list<array{action: string, actor: ?string, subject_type: ?string, subject_id: ?string, context: array<string, mixed>}>
     */
    public function of(string $action): array
    {
        return array_values(array_filter($this->entries, static fn (array $entry): bool => $entry['action'] === $action));
    }

    public function has(string $action, ?string $subjectId = null): bool
    {
        return array_any($this->of($action), fn (array $entry): bool => $subjectId === null || $entry['subject_id'] === $subjectId);
    }
}
