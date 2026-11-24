<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Contracts\Data;

use InvalidArgumentException;

/**
 * Names a ledger account: a person's account by their Identity account id, or one of the
 * platform's accounts. Accounts are created on first use.
 */
final readonly class AccountRef
{
    private function __construct(
        public AccountType $type,
        public ?string $ownerId,
    ) {}

    public static function of(AccountType $type, string $userId): self
    {
        if (! $type->isPersonal()) {
            throw new InvalidArgumentException("{$type->value} belongs to the platform, not to a person.");
        }

        return new self($type, $userId);
    }

    public static function platform(AccountType $type): self
    {
        if ($type->isPersonal()) {
            throw new InvalidArgumentException("{$type->value} belongs to a person.");
        }

        return new self($type, null);
    }

    public function ownerType(): string
    {
        return $this->ownerId === null ? 'platform' : 'user';
    }

    public function key(): string
    {
        return $this->type->value.':'.($this->ownerId ?? 'platform');
    }
}
