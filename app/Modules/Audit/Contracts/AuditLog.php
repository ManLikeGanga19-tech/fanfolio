<?php

declare(strict_types=1);

namespace App\Modules\Audit\Contracts;

/**
 * Rule 12: every admin action and every change to money, verification or consent
 * writes here. Entries are append-only; the table rejects UPDATE and DELETE.
 *
 * Context holds identifiers and outcomes only: never secrets, codes, passwords,
 * document data or raw personal data.
 */
interface AuditLog
{
    /**
     * @param  array<string, scalar|null|list<scalar>>  $context
     */
    public function record(
        string $action,
        ?string $actorId,
        ?string $subjectType = null,
        ?string $subjectId = null,
        array $context = [],
    ): void;
}
