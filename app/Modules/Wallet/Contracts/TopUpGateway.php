<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Contracts;

use App\Modules\Wallet\Contracts\Data\CheckoutRequest;
use App\Modules\Wallet\Contracts\Data\CheckoutSession;
use App\Modules\Wallet\Contracts\Data\GatewayEvent;
use App\Support\Webhooks\InvalidWebhookSignature;

/**
 * Card or crypto top-up processor (CCBill, Segpay and/or crypto: not chosen).
 *
 * The processor's hosted checkout collects payment, so card data never touches our servers.
 * Credits are granted only after a verified, signed webhook.
 */
interface TopUpGateway
{
    public function name(): string;

    public function createCheckout(CheckoutRequest $request): CheckoutSession;

    /**
     * Verify the signature and timestamp, then parse the event (SEC-13).
     *
     * @param  array<string, string>  $headers  lower-cased header names
     *
     * @throws InvalidWebhookSignature
     */
    public function parseWebhook(string $payload, array $headers): GatewayEvent;
}
