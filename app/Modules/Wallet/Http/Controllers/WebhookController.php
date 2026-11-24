<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Http\Controllers;

use App\Modules\Wallet\Actions\ReceiveGatewayWebhook;
use App\Support\Webhooks\InvalidWebhookSignature;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * POST /webhooks/{gateway} (spec "Inbound webhooks"): no session, no CSRF; the signature
 * is the authentication. Answers 200 at once (a repeat too), 400 for a bad signature.
 */
final class WebhookController
{
    public function __invoke(Request $request, string $gateway, ReceiveGatewayWebhook $receive): Response
    {
        $headers = [];
        foreach ($request->headers->all() as $name => $values) {
            $headers[strtolower($name)] = (string) ($values[0] ?? '');
        }

        try {
            $receive->handle($gateway, $request->getContent(), $headers);
        } catch (InvalidWebhookSignature) {
            return response('', 400);
        }

        return response('', 200);
    }
}
