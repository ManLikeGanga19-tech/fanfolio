<?php

declare(strict_types=1);

namespace App\Modules\TrustSafety\Actions;

use App\Modules\Audit\Contracts\AuditLog;
use App\Modules\Identity\Contracts\AccountDirectory;
use App\Modules\Identity\Contracts\Data\AccountSummary;
use App\Modules\TrustSafety\Enums\CaseSource;
use App\Modules\TrustSafety\Enums\ReportReason;
use App\Modules\TrustSafety\Exceptions\TrustSafetyException;
use App\Modules\TrustSafety\Models\ModerationCase;
use App\Modules\TrustSafety\Models\Report;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Plan M8a use cases 25, 28 and 29: a fan or creator reports an account or a creator
 * page ("Report" sheet, canvas FanMore). Reports about the same account join its open
 * case, which keeps the most serious reason and counts the reports. Anything that may
 * involve a minor makes the case urgent, with a 1-hour target, for an admin. The
 * reporter is never shown to staff.
 */
final readonly class FileReport
{
    public const int DETAILS_MAX = 500;

    public const int URGENT_MINUTES = 60;

    public function __construct(
        private AccountDirectory $directory,
        private AuditLog $audit,
    ) {}

    /**
     * @param  ModerationCase::ACCOUNT|ModerationCase::CREATOR_PAGE  $kind
     */
    public function handle(string $reporterId, string $targetUserId, string $kind, ReportReason $reason, ?string $details = null): ModerationCase
    {
        $details = $details === null ? null : trim($details);
        $details = $details === '' ? null : $details;

        if ($details !== null && mb_strlen($details) > self::DETAILS_MAX) {
            throw new TrustSafetyException('Keep the details under '.self::DETAILS_MAX.' characters.', 'details');
        }

        if ($reporterId === $targetUserId) {
            throw new TrustSafetyException('You cannot report yourself.', 'target');
        }

        $target = $this->directory->find($targetUserId);
        if (! $target instanceof AccountSummary || $target->isStaff) {
            throw new TrustSafetyException('We could not find that account.', 'target');
        }

        return DB::transaction(function () use ($reporterId, $targetUserId, $kind, $reason, $details): ModerationCase {
            // One case per account at a time, even when reports arrive together.
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['moderation:'.$targetUserId]);

            // A repeat from the same person within a day is accepted quietly and not counted.
            $repeat = Report::query()
                ->where('reporter_id', $reporterId)
                ->where('target_user_id', $targetUserId)
                ->where('created_at', '>', now()->subDay())
                ->latest('created_at')
                ->first();

            if ($repeat instanceof Report) {
                return ModerationCase::query()->findOrFail($repeat->case_id);
            }

            $case = ModerationCase::query()
                ->pending()
                ->where('target_user_id', $targetUserId)
                ->where('source', CaseSource::UserReport)
                ->lockForUpdate()
                ->first();

            if (! $case instanceof ModerationCase) {
                $case = new ModerationCase;
                $case->forceFill([
                    'target_user_id' => $targetUserId,
                    'target_kind' => $kind,
                    'source' => CaseSource::UserReport,
                    'reason' => $reason,
                ]);
            }

            if ($reason->rank() < $case->reason->rank()) {
                $case->reason = $reason;
            }

            if ($reason->isUrgent() && ! $case->urgent) {
                $case->forceFill(['urgent' => true, 'due_at' => CarbonImmutable::now()->addMinutes(self::URGENT_MINUTES)]);
            }

            $case->report_count++;
            $case->save();

            $report = new Report;
            $report->forceFill([
                'case_id' => $case->id,
                'reporter_id' => $reporterId,
                'target_user_id' => $targetUserId,
                'reason' => $reason,
                'details' => $details,
                'created_at' => now(),
            ])->save();

            $this->audit->record('report.filed', $reporterId, 'moderation_case', $case->id, [
                'reason' => $reason->value,
                'urgent' => $case->urgent,
            ]);

            return $case;
        });
    }
}
