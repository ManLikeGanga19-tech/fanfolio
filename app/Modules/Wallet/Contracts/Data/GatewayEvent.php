<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Contracts\Data;

use App\Support\Money\Money;
use DateTimeImmutable;

/**
 * A verified webhook event. Deduplicate on eventId before acting on it.
 */
final readonly class GatewayEvent
{
    public function __construct(
        public string $gateway,
        public string $eventId,
        public GatewayEventType $type,
        public string $gatewayRef,
        public Money $amount,
        public DateTimeImmutable $occurredAt,
    ) {}
}
