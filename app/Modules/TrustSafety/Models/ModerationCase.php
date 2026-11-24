<?php

declare(strict_types=1);

namespace App\Modules\TrustSafety\Models;

use App\Modules\TrustSafety\Enums\CaseDecision;
use App\Modules\TrustSafety\Enums\CaseSource;
use App\Modules\TrustSafety\Enums\CaseStatus;
use App\Modules\TrustSafety\Enums\ReportReason;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One case in the moderation queue (canvas AdminModeration).
 *
 * @property string $id
 * @property int $number
 * @property string $target_user_id
 * @property string $target_kind
 * @property CaseSource $source
 * @property ReportReason $reason
 * @property bool $urgent
 * @property CarbonImmutable|null $due_at
 * @property CaseStatus $status
 * @property int $report_count
 * @property string|null $opened_by
 * @property string|null $assigned_to
 * @property CarbonImmutable|null $assigned_at
 * @property CaseDecision|null $decision
 * @property string|null $decision_note
 * @property string|null $decided_by
 * @property CarbonImmutable|null $decided_at
 * @property CarbonImmutable $created_at
 */
final class ModerationCase extends Model
{
    use HasUlids;

    public const string ACCOUNT = 'account';

    public const string CREATOR_PAGE = 'creator_page';

    protected $table = 'moderation_cases';

    protected $guarded = ['*'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'open',
        'urgent' => false,
        'report_count' => 0,
    ];

    /**
     * @return HasMany<Report, $this>
     */
    public function reports(): HasMany
    {
        return $this->hasMany(Report::class, 'case_id');
    }

    /** Open or escalated: still in the queue. */
    public function isPending(): bool
    {
        return $this->status !== CaseStatus::Closed;
    }

    /** Only an admin may decide it (escalated, or it may involve a minor). */
    public function needsAdmin(): bool
    {
        return $this->status === CaseStatus::Escalated || $this->urgent;
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function pending(Builder $query): void
    {
        $query->whereIn('status', [CaseStatus::Open, CaseStatus::Escalated]);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'source' => CaseSource::class,
            'reason' => ReportReason::class,
            'urgent' => 'boolean',
            'due_at' => 'immutable_datetime',
            'status' => CaseStatus::class,
            'report_count' => 'integer',
            'assigned_at' => 'immutable_datetime',
            'decision' => CaseDecision::class,
            'decided_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
