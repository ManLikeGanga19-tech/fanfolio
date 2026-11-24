<?php

declare(strict_types=1);

namespace App\Modules\TrustSafety\Actions;

use App\Modules\Audit\Contracts\AuditLog;
use App\Modules\Identity\Contracts\AccountDirectory;
use App\Modules\Identity\Contracts\Accounts;
use App\Modules\Identity\Contracts\StaffAbility;
use App\Modules\TrustSafety\Enums\CaseSource;
use App\Modules\TrustSafety\Enums\ReportReason;
use App\Modules\TrustSafety\Models\ModerationCase;
use App\Modules\TrustSafety\Models\Report;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Plan M8a use case 23: staff flag an account from Users & bans, which opens a case in
 * the moderation queue ("Flagged by staff"). The note is shown with the case. An account
 * already flagged and still pending keeps its one case.
 */
final readonly class FlagAccount
{
    use DecidesOnAccounts;

    public function __construct(
        private Accounts $accounts,
        private AccountDirectory $directory,
        private AuditLog $audit,
    ) {}

    public function handle(string $userId, string $staffId, ReportReason $reason, string $note): ModerationCase
    {
        $this->target($userId, $staffId, StaffAbility::FlagUser);
        $note = self::note($note);

        return DB::transaction(function () use ($userId, $staffId, $reason, $note): ModerationCase {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['moderation:'.$userId]);

            $case = ModerationCase::query()
                ->pending()
                ->where('target_user_id', $userId)
                ->where('source', CaseSource::StaffFlag)
                ->lockForUpdate()
                ->first();

            if (! $case instanceof ModerationCase) {
                $case = new ModerationCase;
                $case->forceFill([
                    'target_user_id' => $userId,
                    'target_kind' => ModerationCase::ACCOUNT,
                    'source' => CaseSource::StaffFlag,
                    'reason' => $reason,
                    'opened_by' => $staffId,
                ]);
            } elseif ($reason->rank() < $case->reason->rank()) {
                $case->reason = $reason;
            }

            if ($reason->isUrgent() && ! $case->urgent) {
                $case->forceFill(['urgent' => true, 'due_at' => CarbonImmutable::now()->addMinutes(FileReport::URGENT_MINUTES)]);
            }

            $case->save();

            // The note travels with the case like a report's details.
            $flag = new Report;
            $flag->forceFill([
                'case_id' => $case->id,
                'reporter_id' => $staffId,
                'target_user_id' => $userId,
                'reason' => $reason,
                'details' => $note,
                'created_at' => now(),
            ])->save();

            $this->audit->record('case.flagged', $staffId, 'moderation_case', $case->id, [
                'user' => $userId,
                'reason' => $reason->value,
                'note' => $note,
            ]);

            return $case;
        });
    }
}
