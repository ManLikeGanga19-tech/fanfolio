<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Contracts\SmsSender;
use App\Modules\Identity\Exceptions\IdentityException;
use App\Modules\Identity\Models\PhoneVerification;
use App\Modules\Identity\Models\User;
use App\Support\Security\KeyedHash;

/**
 * Sign up step 2: sends a one-time code by SMS. Only the code's keyed hash is stored,
 * and a new code replaces any earlier one.
 */
final readonly class SendPhoneCode
{
    public const string PHONE_PURPOSE = 'phone';

    public function __construct(
        private SmsSender $sms,
        private KeyedHash $hash,
    ) {}

    public static function codePurpose(User $user): string
    {
        return 'phone-code:'.$user->id;
    }

    public function handle(User $user, string $phoneE164): PhoneVerification
    {
        $phoneHash = $this->hash->make(self::PHONE_PURPOSE, $phoneE164);

        $taken = User::query()
            ->where('phone_hash', $phoneHash)
            ->whereKeyNot($user->id)
            ->exists();

        if ($taken) {
            throw new IdentityException('This number is already linked to another account.', 'phone');
        }

        $wait = $this->secondsUntilResend($user);
        if ($wait > 0) {
            throw new IdentityException('Resend code in '.self::clock($wait).'.', 'phone');
        }

        $digits = config()->integer('fanfolio.identity.phone_code.digits');
        $ttl = config()->integer('fanfolio.identity.phone_code.ttl_minutes');
        $code = str_pad((string) random_int(0, (10 ** $digits) - 1), $digits, '0', STR_PAD_LEFT);

        $verification = PhoneVerification::query()->create([
            'user_id' => $user->id,
            'phone_e164' => $phoneE164,
            'phone_hash' => $phoneHash,
            'code_hash' => $this->hash->make(self::codePurpose($user), $code),
            'attempts' => 0,
            'expires_at' => now()->addMinutes($ttl),
        ]);

        $this->sms->send($phoneE164, "Your FanFolio code is {$code}. It expires in {$ttl} minutes. Never share it.");

        return $verification;
    }

    public function secondsUntilResend(User $user): int
    {
        $latest = PhoneVerification::query()
            ->where('user_id', $user->id)
            ->latest('created_at')
            ->first();

        if ($latest === null) {
            return 0;
        }

        $readyAt = $latest->created_at->addSeconds(config()->integer('fanfolio.identity.phone_code.resend_seconds'));

        return max(0, (int) ceil(now()->diffInSeconds($readyAt, false)));
    }

    /** 42 seconds as "0:42", matching the screen. */
    public static function clock(int $seconds): string
    {
        return intdiv($seconds, 60).':'.str_pad((string) ($seconds % 60), 2, '0', STR_PAD_LEFT);
    }
}
