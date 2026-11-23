<?php

declare(strict_types=1);

namespace App\Modules\Audit\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Read model for audit entries. Writes go through the AuditLog contract.
 *
 * @property string $id
 * @property string|null $actor_id
 * @property string $action
 * @property string|null $subject_type
 * @property string|null $subject_id
 * @property array<string, mixed> $context
 * @property string|null $ip
 * @property string|null $user_agent
 * @property CarbonImmutable $occurred_at
 */
final class AuditEntry extends Model
{
    use HasUlids;

    public $timestamps = false;

    protected $table = 'audit_log';

    /** @var list<string> */
    protected $guarded = [];

    protected static function booted(): void
    {
        self::updating(static fn (): never => throw new LogicException('Audit entries are append-only.'));
        self::deleting(static fn (): never => throw new LogicException('Audit entries are append-only.'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'context' => 'array',
            'occurred_at' => 'immutable_datetime',
        ];
    }
}
