<?php

declare(strict_types=1);

namespace App\Modules\Identity\Contracts\Data;

use Carbon\CarbonImmutable;

/**
 * What staff see about an account on the Users & bans board (rule 13: no email, phone,
 * legal name or date of birth). `status` is the effective one: a suspension that has
 * ended reads as active.
 */
final readonly class AccountSummary
{
    public const string ACTIVE = 'active';

    public const string PAUSED = 'paused';

    public const string SUSPENDED = 'suspended';

    public const string BANNED = 'banned';

    /**
     * @param  'fan'|'creator'|'moderator'|'admin'  $role
     * @param  self::ACTIVE|self::PAUSED|self::SUSPENDED|self::BANNED  $status
     */
    public function __construct(
        public string $id,
        public string $username,
        public string $role,
        public bool $isStaff,
        public bool $ageVerified,
        public CarbonImmutable $joinedAt,
        public string $status,
        public ?CarbonImmutable $suspendedUntil,
        public ?CarbonImmutable $restrictedAt,
        public int $activeDevices,
        public int $deviceLimit,
    ) {}

    public function isRestricted(): bool
    {
        return $this->status === self::SUSPENDED || $this->status === self::BANNED;
    }
}
