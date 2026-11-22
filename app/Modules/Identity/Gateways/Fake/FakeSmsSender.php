<?php

declare(strict_types=1);

namespace App\Modules\Identity\Gateways\Fake;

use App\Modules\Identity\Contracts\SmsSender;
use App\Support\Gateways\FakeDriver;
use Psr\Log\LoggerInterface;

/**
 * Records SMS in memory for tests and writes them to the log for local development.
 */
final class FakeSmsSender implements FakeDriver, SmsSender
{
    /** @var list<array{to: string, message: string}> */
    private array $sent = [];

    public function __construct(private readonly LoggerInterface $logger) {}

    public function send(string $phoneE164, string $message): void
    {
        $this->sent[] = ['to' => $phoneE164, 'message' => $message];
        $this->logger->info('Fake SMS', ['to' => $phoneE164, 'message' => $message]);
    }

    /**
     * @return list<array{to: string, message: string}>
     */
    public function sent(): array
    {
        return $this->sent;
    }

    public function lastMessageTo(string $phoneE164): ?string
    {
        $matches = array_values(array_filter($this->sent, static fn (array $sms): bool => $sms['to'] === $phoneE164));

        return $matches === [] ? null : $matches[count($matches) - 1]['message'];
    }
}
