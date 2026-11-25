<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Gateways\Fake;

use App\Modules\Wallet\Contracts\DisputeGateway;
use App\Support\Gateways\FakeDriver;
use RuntimeException;

/**
 * Local and test stand-in for the processor's dispute desk. It remembers what it was
 * sent; the outcome arrives as a signed webhook from the fake processor console.
 */
final class FakeDisputeGateway implements DisputeGateway, FakeDriver
{
    /** @var array<string, array{action: 'evidence'|'accept', evidence: list<string>}> */
    public array $answered = [];

    private ?string $failure = null;

    /** The next call fails with this message, as a processor outage would. */
    public function willFail(string $message = 'The processor did not answer.'): self
    {
        $this->failure = $message;

        return $this;
    }

    public function submitEvidence(string $caseRef, array $evidence): void
    {
        $this->answer($caseRef, 'evidence', $evidence);
    }

    public function accept(string $caseRef): void
    {
        $this->answer($caseRef, 'accept', []);
    }

    /**
     * @param  'evidence'|'accept'  $action
     * @param  list<string>  $evidence
     */
    private function answer(string $caseRef, string $action, array $evidence): void
    {
        if ($this->failure !== null) {
            $message = $this->failure;
            $this->failure = null;

            throw new RuntimeException($message);
        }

        $this->answered[$caseRef] = ['action' => $action, 'evidence' => $evidence];
    }
}
