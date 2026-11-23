<?php

declare(strict_types=1);

namespace App\Modules\Creators\Actions;

use App\Modules\Audit\Contracts\AuditLog;
use App\Modules\Creators\Enums\CreatorStatus;
use App\Modules\Creators\Enums\KycStatus;
use App\Modules\Creators\Exceptions\CreatorException;
use App\Modules\Creators\Models\Creator;
use App\Modules\Creators\Models\PayoutMethod;
use App\Modules\Creators\Support\PayoutDetails;
use App\Modules\Wallet\Contracts\Data\PayoutRail;
use Illuminate\Support\Facades\DB;

/**
 * Step 4 of 4: where the money goes. The route needs a step-up in the last 10 minutes
 * (SEC-04). A creator has one payout method at a time: a new one replaces the old,
 * which is revoked, never deleted. The name on the account is compared with the legal
 * name from the ID check; a mismatch is flagged for staff, not refused.
 */
final readonly class AddPayoutMethod
{
    public function __construct(private AuditLog $audit) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(Creator $creator, PayoutRail $rail, array $input): PayoutMethod
    {
        $parsed = PayoutDetails::from($rail, $input);
        $accountName = PayoutDetails::accountName($input['account_name'] ?? null);

        return DB::transaction(function () use ($creator, $rail, $parsed, $accountName): PayoutMethod {
            $creator = Creator::query()->whereKey($creator->id)->lockForUpdate()->firstOrFail();

            if ($creator->status === CreatorStatus::Suspended) {
                throw new CreatorException('Your account is suspended. Contact support.', 'rail');
            }

            if (! in_array($creator->kyc_status, [KycStatus::Review, KycStatus::Approved], true)) {
                throw new CreatorException('Verify your identity first.', 'kyc');
            }

            $previous = $creator->payoutMethods()->whereNull('revoked_at')->lockForUpdate()->get();

            foreach ($previous as $old) {
                $old->forceFill(['revoked_at' => now(), 'is_default' => false])->save();
                $this->audit->record('creator.payout_method_revoked', $creator->user_id, 'payout_method', $old->id, [
                    'rail' => $old->rail->value,
                    'hint' => $old->display_hint,
                ]);
            }

            $matches = PayoutDetails::namesMatch($accountName, $creator->verifiedLegalName());

            $method = PayoutMethod::query()->create([
                'creator_id' => $creator->id,
                'rail' => $rail,
                'details' => $parsed['details'],
                'account_name' => $accountName,
                'display_hint' => $parsed['hint'],
                'name_matches_kyc' => $matches,
                'is_default' => true,
            ]);

            $this->audit->record('creator.payout_method_added', $creator->user_id, 'payout_method', $method->id, [
                'rail' => $rail->value,
                'hint' => $method->display_hint,
                'name_matches_kyc' => $matches,
            ]);

            return $method;
        });
    }
}
