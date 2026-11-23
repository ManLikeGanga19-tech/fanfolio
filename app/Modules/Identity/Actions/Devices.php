<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Audit\Contracts\AuditLog;
use App\Modules\Identity\Events\DeviceRevoked;
use App\Modules\Identity\Exceptions\DeviceLimitReached;
use App\Modules\Identity\Exceptions\IdentityException;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Models\UserDevice;
use App\Modules\Identity\Support\DeviceLabel;
use App\Support\Security\KeyedHash;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * RegisterDevice, RevokeDevice and SignOutOtherDevices (SEC-05, decision D-03).
 *
 * A device is a browser holding a random device token in an HttpOnly cookie. Signing in
 * on a known device reuses its row; a new device is refused once the account has the
 * maximum number of active devices.
 */
final readonly class Devices
{
    /** The signed-in device for this browser session. */
    public const string SESSION_KEY = 'identity.device_id';

    private const string PURPOSE = 'device';

    public function __construct(
        private KeyedHash $hash,
        private AuditLog $audit,
    ) {}

    public static function limit(): int
    {
        return config()->integer('fanfolio.identity.max_active_devices');
    }

    /**
     * RegisterDevice.
     *
     * @throws DeviceLimitReached
     */
    public function register(User $user, string $deviceToken, ?string $userAgent, ?string $ip): UserDevice
    {
        $fingerprint = $this->hash->make(self::PURPOSE, $deviceToken);

        $device = DB::transaction(function () use ($user, $fingerprint, $userAgent, $ip): ?UserDevice {
            // Serialise sign-ins for one account so two new devices cannot both take the last slot.
            User::query()->whereKey($user->id)->lockForUpdate()->first();

            $existing = UserDevice::query()
                ->where('user_id', $user->id)
                ->where('device_fingerprint_hash', $fingerprint)
                ->active()
                ->first();

            if ($existing !== null) {
                $existing->forceFill(['last_seen_at' => now(), 'last_ip' => $ip, 'label' => DeviceLabel::fromUserAgent($userAgent)])->save();

                return $existing;
            }

            if (UserDevice::query()->where('user_id', $user->id)->active()->count() >= self::limit()) {
                return null;
            }

            $device = UserDevice::query()->create([
                'user_id' => $user->id,
                'device_fingerprint_hash' => $fingerprint,
                'label' => DeviceLabel::fromUserAgent($userAgent),
                'last_ip' => $ip,
                'last_seen_at' => now(),
            ]);

            $this->audit->record('identity.device_added', $user->id, 'device', $device->id);

            return $device;
        });

        if ($device === null) {
            // Recorded outside the transaction so the refusal is kept.
            $this->audit->record('identity.device_refused', $user->id, 'user', $user->id, ['limit' => self::limit()]);

            throw new DeviceLimitReached(self::limit());
        }

        return $device;
    }

    /**
     * @return Collection<int, UserDevice>
     */
    public function active(User $user): Collection
    {
        return UserDevice::query()
            ->where('user_id', $user->id)
            ->active()
            ->orderByDesc('last_seen_at')
            ->get();
    }

    /**
     * RevokeDevice. Only the owner's devices can be revoked (the caller authorises too).
     */
    public function revoke(User $user, string $deviceId): void
    {
        DB::transaction(function () use ($user, $deviceId): void {
            $device = UserDevice::query()
                ->where('user_id', $user->id)
                ->whereKey($deviceId)
                ->active()
                ->lockForUpdate()
                ->first();

            if ($device === null) {
                throw new IdentityException('That device is already signed out.', 'device');
            }

            $this->markRevoked($user, $device);
        });
    }

    /**
     * SignOutOtherDevices.
     */
    public function revokeOthers(User $user, string $keepDeviceId): int
    {
        return DB::transaction(function () use ($user, $keepDeviceId): int {
            $others = UserDevice::query()
                ->where('user_id', $user->id)
                ->whereKeyNot($keepDeviceId)
                ->active()
                ->lockForUpdate()
                ->get();

            foreach ($others as $device) {
                $this->markRevoked($user, $device);
            }

            return $others->count();
        });
    }

    public function isActiveFor(User $user, string $deviceId): bool
    {
        return UserDevice::query()->where('user_id', $user->id)->whereKey($deviceId)->active()->exists();
    }

    /**
     * Records activity at most every five minutes to keep writes low.
     */
    public function touch(string $deviceId, ?string $ip): void
    {
        UserDevice::query()
            ->whereKey($deviceId)
            ->where('last_seen_at', '<', now()->subMinutes(5))
            ->update(['last_seen_at' => now(), 'last_ip' => $ip]);
    }

    private function markRevoked(User $user, UserDevice $device): void
    {
        $device->forceFill(['revoked_at' => now()])->save();

        $this->audit->record('identity.device_revoked', $user->id, 'device', $device->id);
        DeviceRevoked::dispatch($user->id, $device->id);
    }
}
