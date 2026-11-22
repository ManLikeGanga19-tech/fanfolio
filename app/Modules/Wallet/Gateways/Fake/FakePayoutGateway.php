<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Gateways\Fake;

use App\Modules\Wallet\Contracts\Data\PayoutInstruction;
use App\Modules\Wallet\Contracts\Data\PayoutRail;
use App\Modules\Wallet\Contracts\Data\PayoutResult;
use App\Modules\Wallet\Contracts\Data\PayoutStatus;
use App\Modules\Wallet\Contracts\PayoutGateway;
use App\Support\Gateways\FakeDriver;
use Illuminate\Contracts\Cache\Repository as Cache;
use InvalidArgumentException;
use RuntimeException;

/**
 * Local and test stand-in for one payout rail. Idempotent on payoutId, like a real driver must be.
 */
final class FakePayoutGateway implements FakeDriver, PayoutGateway
{
    private PayoutStatus $nextStatus = PayoutStatus::Sent;

    private ?string $nextFailureReason = null;

    public function __construct(
        private readonly PayoutRail $rail,
        private readonly Cache $cache,
    ) {}

    public function willReturn(PayoutStatus $status, ?string $failureReason = null): self
    {
        $this->nextStatus = $status;
        $this->nextFailureReason = $failureReason;

        return $this;
    }

    public function rail(): PayoutRail
    {
        return $this->rail;
    }

    public function send(PayoutInstruction $instruction): PayoutResult
    {
        if ($instruction->rail !== $this->rail) {
            throw new InvalidArgumentException("Instruction for {$instruction->rail->value} sent to {$this->rail->value} gateway.");
        }

        $ref = 'fake_po_'.$this->rail->value.'_'.$instruction->payoutId;
        $existing = $this->cache->get($this->key($ref));

        if (is_array($existing)) {
            return $this->hydrate($ref, $existing);
        }

        $this->cache->put($this->key($ref), ['status' => $this->nextStatus->value, 'reason' => $this->nextFailureReason], now()->addDays(30));

        return new PayoutResult($this->nextStatus, $ref, $this->nextFailureReason);
    }

    public function status(string $gatewayRef): PayoutResult
    {
        $existing = $this->cache->get($this->key($gatewayRef));

        if (! is_array($existing)) {
            throw new RuntimeException("Unknown fake payout [{$gatewayRef}].");
        }

        return $this->hydrate($gatewayRef, $existing);
    }

    /**
     * @param  array<mixed>  $stored
     */
    private function hydrate(string $ref, array $stored): PayoutResult
    {
        $status = PayoutStatus::from(is_string($stored['status'] ?? null) ? $stored['status'] : PayoutStatus::Pending->value);
        $reason = $stored['reason'] ?? null;

        return new PayoutResult($status, $ref, is_string($reason) ? $reason : null);
    }

    private function key(string $ref): string
    {
        return 'fake:payout:'.$ref;
    }
}
