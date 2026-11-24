<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Audit\Contracts\AuditLog;
use App\Modules\Identity\Exceptions\IdentityException;
use App\Modules\Identity\Models\EmailVerification;
use App\Modules\Identity\Models\User;
use App\Support\Security\KeyedHash;
use Illuminate\Support\Facades\DB;

/**
 * Checks the latest email code: at most 5 attempts within 10 minutes. Success marks
 * the email verified. Each wrong attempt is committed before the refusal.
 */
final readonly class VerifyEmail
{
    public function __construct(
        private KeyedHash $hash,
        private AuditLog $audit,
    ) {}

    public function handle(User $user, string $code): void
    {
        $matched = DB::transaction(function () use ($user, $code): bool {
            $verification = EmailVerification::query()
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

            if ($verification->attempts >= config()->integer('fanfolio.identity.email_code.max_attempts')) {
                throw new IdentityException('Too many attempts. Request a new code.', 'code');
            }

            $verification->increment('attempts');

            if (! $this->hash->matches(SendEmailCode::codePurpose($user), $code, $verification->code_hash)) {
                return false;
            }

            $verification->forceFill(['verified_at' => now()])->save();
            $user->forceFill(['email_verified_at' => now()])->save();

            $this->audit->record('identity.email_verified', $user->id, 'user', $user->id);

            return true;
        });

        if (! $matched) {
            throw new IdentityException('That code is not right. Check the email and try again.', 'code');
        }
    }
}
