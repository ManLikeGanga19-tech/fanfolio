<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Audit\Contracts\AuditLog;
use App\Modules\Identity\Exceptions\IdentityException;
use App\Modules\Identity\Models\TotpSecret;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use PragmaRX\Google2FA\Google2FA;

/**
 * Authenticator app (RFC 6238, 6 digits, 30 s): EnableTotp, ConfirmTotp and the check
 * used at login and step-up. A code is accepted once: the time step it matched is
 * stored and older or equal steps are refused afterwards.
 */
final readonly class Totp
{
    private const int WINDOW = 1;

    public function __construct(
        private Google2FA $google2fa,
        private SyncMfaStatus $sync,
        private RecoveryCodes $recoveryCodes,
        private AuditLog $audit,
    ) {}

    /**
     * EnableTotp: creates (or replaces) an unconfirmed secret.
     *
     * @return array{secret: string, uri: string}
     */
    public function enable(User $user): array
    {
        if ($user->hasConfirmedTotp()) {
            throw new IdentityException('An authenticator app is already set up. Remove it first.', 'code');
        }

        $secret = $this->google2fa->generateSecretKey(32);

        TotpSecret::query()->updateOrCreate(
            ['user_id' => $user->id],
            ['secret' => $secret, 'confirmed_at' => null, 'last_used_timestep' => null],
        );

        return ['secret' => $secret, 'uri' => self::uri($user, $secret)];
    }

    /**
     * ConfirmTotp: the first valid code turns the app on and returns fresh recovery
     * codes (shown once), unless the user already holds unused ones.
     *
     * @return list<string>
     */
    public function confirm(User $user, string $code): array
    {
        return DB::transaction(function () use ($user, $code): array {
            $totp = TotpSecret::query()->where('user_id', $user->id)->lockForUpdate()->first();

            if ($totp === null || $totp->isConfirmed()) {
                throw new IdentityException('Start the set-up again.', 'code');
            }

            $step = $this->match($totp, $code);
            if ($step === null) {
                throw new IdentityException('That code is not right. Check the app and try again.', 'code');
            }

            $totp->forceFill(['confirmed_at' => now(), 'last_used_timestep' => $step])->save();
            $this->audit->record('identity.totp_added', $user->id, 'user', $user->id);
            $this->sync->handle($user);

            return $this->recoveryCodes->hasUnused($user) ? [] : $this->recoveryCodes->issue($user);
        });
    }

    /**
     * Checks a code from a confirmed app, once.
     */
    public function verify(User $user, string $code): bool
    {
        return DB::transaction(function () use ($user, $code): bool {
            $totp = TotpSecret::query()
                ->where('user_id', $user->id)
                ->whereNotNull('confirmed_at')
                ->lockForUpdate()
                ->first();

            if ($totp === null) {
                return false;
            }

            $step = $this->match($totp, $code);
            if ($step === null) {
                return false;
            }

            $totp->forceFill(['last_used_timestep' => $step])->save();

            return true;
        });
    }

    public function remove(User $user): void
    {
        DB::transaction(function () use ($user): void {
            if ($user->role->requiresMfa() && ! $user->hasPasskey()) {
                throw new IdentityException('Your account needs a second sign-in method. Add a passkey before removing the app.', 'totp');
            }

            TotpSecret::query()->where('user_id', $user->id)->delete();
            $this->audit->record('identity.totp_removed', $user->id, 'user', $user->id);
            $this->sync->handle($user);
        });
    }

    public static function currentTimestep(): int
    {
        return intdiv(now()->getTimestamp(), 30);
    }

    private function match(TotpSecret $totp, string $code): ?int
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';

        if (preg_match('/^\d{6}$/', $code) !== 1) {
            return null;
        }

        $current = self::currentTimestep();

        // Check each step in the window ourselves so the clock comes from the app
        // (testable) and a step at or before the last accepted one never passes.
        for ($step = $current - self::WINDOW; $step <= $current + self::WINDOW; $step++) {
            if ($totp->last_used_timestep !== null && $step <= $totp->last_used_timestep) {
                continue;
            }

            if (hash_equals($this->google2fa->oathTotp($totp->secret, $step), $code)) {
                return $step;
            }
        }

        return null;
    }

    public static function uri(User $user, string $secret): string
    {
        $issuer = rawurlencode(config()->string('fanfolio.brand.name'));
        $label = $issuer.':'.rawurlencode('@'.$user->username);

        return "otpauth://totp/{$label}?secret={$secret}&issuer={$issuer}&algorithm=SHA1&digits=6&period=30";
    }
}
