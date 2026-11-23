<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Exceptions\IdentityException;
use App\Modules\Identity\Models\EmailVerification;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Notifications\EmailVerificationCode;
use App\Support\Security\KeyedHash;

/**
 * Sign up step 2 (decision D-12): sends a one-time code to the account's own email.
 * Only the code's keyed hash is stored, and a new code replaces any earlier one.
 */
final readonly class SendEmailCode
{
    public function __construct(private KeyedHash $hash) {}

    public static function codePurpose(User $user): string
    {
        return 'email-code:'.$user->id;
    }

    public function handle(User $user): EmailVerification
    {
        if ($user->hasVerifiedEmail()) {
            throw new IdentityException('Your email is already verified.', 'email');
        }

        $wait = $this->secondsUntilResend($user);
        if ($wait > 0) {
            throw new IdentityException('Resend code in '.self::clock($wait).'.', 'email');
        }

        $digits = config()->integer('fanfolio.identity.email_code.digits');
        $ttl = config()->integer('fanfolio.identity.email_code.ttl_minutes');
        $code = str_pad((string) random_int(0, (10 ** $digits) - 1), $digits, '0', STR_PAD_LEFT);

        $verification = EmailVerification::query()->create([
            'user_id' => $user->id,
            'code_hash' => $this->hash->make(self::codePurpose($user), $code),
            'attempts' => 0,
            'expires_at' => now()->addMinutes($ttl),
        ]);

        $user->notify(new EmailVerificationCode($code, $ttl));

        return $verification;
    }

    public function secondsUntilResend(User $user): int
    {
        $latest = EmailVerification::query()
            ->where('user_id', $user->id)
            ->latest('created_at')
            ->first();

        if ($latest === null) {
            return 0;
        }

        $readyAt = $latest->created_at->addSeconds(config()->integer('fanfolio.identity.email_code.resend_seconds'));

        return max(0, (int) ceil(now()->diffInSeconds($readyAt, false)));
    }

    /** 42 seconds as "0:42", matching the screen. */
    public static function clock(int $seconds): string
    {
        return intdiv($seconds, 60).':'.str_pad((string) ($seconds % 60), 2, '0', STR_PAD_LEFT);
    }
}
