<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Audit\Contracts\AuditLog;
use App\Modules\Identity\Exceptions\IdentityException;
use App\Modules\Identity\Models\PhoneVerification;
use App\Modules\Identity\Models\User;
use App\Support\Security\KeyedHash;
use Illuminate\Support\Facades\DB;

/**
 * Checks the latest code: at most 5 attempts within 10 minutes. Success links the
 * number to the account. Each wrong attempt is committed before the refusal.
 */
final readonly class VerifyPhone
{
    public function __construct(
        private KeyedHash $hash,
        private AuditLog $audit,
    ) {}

    public function handle(User $user, string $code): void
    {
        $matched = DB::transaction(function () use ($user, $code): bool {
            $verification = PhoneVerification::query()
                ->where('user_id', $user->id)
                ->latest('created_at')
                ->lockForUpdate()
                ->first();

            if ($verification === null || $verification->verified_at !== null) {
                throw new IdentityException('Request a code first.', 'code');
            }

            if ($verification->isExpired()) {
                throw new IdentityException('This code has expired. Request a new one.', 'code');
            }

            if ($verification->attempts >= config()->integer('fanfolio.identity.phone_code.max_attempts')) {
                throw new IdentityException('Too many attempts. Request a new code.', 'code');
            }

            $verification->increment('attempts');

            if (! $this->hash->matches(SendPhoneCode::codePurpose($user), $code, $verification->code_hash)) {
                return false;
            }

            $verification->forceFill(['verified_at' => now()])->save();

            $user->forceFill([
                'phone_e164' => $verification->phone_e164,
                'phone_hash' => $verification->phone_hash,
                'phone_verified_at' => now(),
            ])->save();

            $this->audit->record('identity.phone_verified', $user->id, 'user', $user->id);

            return true;
        });

        if (! $matched) {
            throw new IdentityException('That code is not right. Check the SMS and try again.', 'code');
        }
    }
}
