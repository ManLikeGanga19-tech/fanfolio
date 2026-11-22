<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Audit\Contracts\AuditLog;
use App\Modules\Identity\Models\RecoveryCode;
use App\Modules\Identity\Models\User;
use App\Support\Security\KeyedHash;
use Illuminate\Support\Facades\DB;

/**
 * One-time backup codes (UseRecoveryCode). Shown once, stored as keyed hashes.
 * Format: XXXXX-XXXXX from an alphabet without look-alike characters.
 */
final readonly class RecoveryCodes
{
    private const string ALPHABET = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';

    private const string PURPOSE = 'recovery-code';

    public function __construct(
        private KeyedHash $hash,
        private AuditLog $audit,
    ) {}

    /**
     * Replaces every unused code with a new set.
     *
     * @return list<string>
     */
    public function issue(User $user): array
    {
        $codes = [];
        for ($i = 0; $i < config()->integer('fanfolio.identity.recovery_codes'); $i++) {
            $codes[] = $this->random(5).'-'.$this->random(5);
        }

        DB::transaction(function () use ($user, $codes): void {
            RecoveryCode::query()->where('user_id', $user->id)->whereNull('used_at')->delete();

            foreach ($codes as $code) {
                RecoveryCode::query()->create([
                    'user_id' => $user->id,
                    'code_hash' => $this->hash->make(self::PURPOSE, $code),
                ]);
            }

            $this->audit->record('identity.recovery_codes_issued', $user->id, 'user', $user->id, ['count' => count($codes)]);
        });

        return $codes;
    }

    public function hasUnused(User $user): bool
    {
        return RecoveryCode::query()->where('user_id', $user->id)->whereNull('used_at')->exists();
    }

    public function remaining(User $user): int
    {
        return RecoveryCode::query()->where('user_id', $user->id)->whereNull('used_at')->count();
    }

    /**
     * UseRecoveryCode: accepts an unused code once.
     */
    public function use(User $user, string $code): bool
    {
        $normalised = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '');

        if (strlen($normalised) !== 10) {
            return false;
        }

        $hash = $this->hash->make(self::PURPOSE, substr($normalised, 0, 5).'-'.substr($normalised, 5));

        $used = RecoveryCode::query()
            ->where('user_id', $user->id)
            ->where('code_hash', $hash)
            ->whereNull('used_at')
            ->update(['used_at' => now()]);

        if ($used !== 1) {
            return false;
        }

        $this->audit->record('identity.recovery_code_used', $user->id, 'user', $user->id, ['remaining' => $this->remaining($user)]);

        return true;
    }

    private function random(int $length): string
    {
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return $out;
    }
}
