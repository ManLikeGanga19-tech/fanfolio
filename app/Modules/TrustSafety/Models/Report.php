<?php

declare(strict_types=1);

namespace App\Modules\TrustSafety\Models;

use App\Modules\TrustSafety\Enums\ReportReason;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * One report from the "Report" sheet. The reporter is kept to stop repeat reports and
 * is never shown to staff (plan M8a use case 26). The details may hold personal data,
 * so they are encrypted.
 *
 * @property string $id
 * @property string $case_id
 * @property string $reporter_id
 * @property string $target_user_id
 * @property ReportReason $reason
 * @property string|null $details
 * @property CarbonImmutable $created_at
 */
final class Report extends Model
{
    use HasUlids;

    public const null UPDATED_AT = null;

    protected $guarded = ['*'];

    /** @var list<string> */
    protected $hidden = ['reporter_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'reason' => ReportReason::class,
            'details' => 'encrypted',
            'created_at' => 'immutable_datetime',
        ];
    }
}
