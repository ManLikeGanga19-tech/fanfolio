<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Jobs;

use App\Modules\Wallet\Actions\ApplyGatewayEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Spec: webhooks are processed on the `payments` queue. Safe to retry: the action is idempotent. */
final class ProcessGatewayWebhook implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [5, 30, 120, 600];

    public function __construct(public readonly string $webhookId) {}

    public function handle(ApplyGatewayEvent $apply): void
    {
        $apply->handle($this->webhookId);
    }
}
