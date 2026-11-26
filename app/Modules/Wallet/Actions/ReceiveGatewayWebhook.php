<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Actions;

use App\Modules\Wallet\Contracts\Data\DisputeNotice;
use App\Modules\Wallet\Contracts\TopUpGateway;
use App\Modules\Wallet\Jobs\ProcessGatewayWebhook;
use App\Support\Webhooks\InvalidWebhookSignature;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Spec "Inbound webhooks" and SEC-13: verify the signature and timestamp, store the raw
 * payload once per event id, answer at once and process on the payments queue. A replayed
 * or duplicated event is stored once and processed once.
 */
final readonly class ReceiveGatewayWebhook
{
    public function __construct(private TopUpGateway $gateway) {}

    /**
     * @param  array<string, string>  $headers  lower-cased header names
     * @return bool whether the event was new
     *
     * @throws InvalidWebhookSignature
     */
    public function handle(string $gatewayName, string $payload, array $headers): bool
    {
        if ($gatewayName !== $this->gateway->name()) {
            throw new InvalidWebhookSignature("Unknown gateway [{$gatewayName}].");
        }

        $event = $this->gateway->parseWebhook($payload, $headers);
        $id = strtolower((string) Str::ulid());

        $inserted = DB::table('gateway_webhooks')->insertOrIgnore([
            'id' => $id,
            'gateway' => $event->gateway,
            'event_id' => $event->eventId,
            'type' => $event->type->value,
            'gateway_ref' => $event->gatewayRef,
            'amount_minor' => $event->amount->amountMinor,
            'currency' => $event->amount->currency,
            'payment_hint' => $event->paymentHint === null ? null : mb_substr($event->paymentHint, 0, 40),
            'case_ref' => $event->dispute?->caseRef,
            'details' => $event->dispute instanceof DisputeNotice ? json_encode($event->dispute->details(), JSON_THROW_ON_ERROR) : null,
            // Read back through GatewayWebhook's "encrypted" cast.
            'payload' => Crypt::encryptString($payload),
            'received_at' => now(),
        ]);

        if ($inserted === 0) {
            return false;
        }

        ProcessGatewayWebhook::dispatch($id)->onQueue('payments')->afterCommit();

        return true;
    }
}
