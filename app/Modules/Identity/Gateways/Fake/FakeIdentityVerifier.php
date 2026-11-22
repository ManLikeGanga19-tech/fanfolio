<?php

declare(strict_types=1);

namespace App\Modules\Identity\Gateways\Fake;

use App\Modules\Identity\Contracts\Data\DocumentImage;
use App\Modules\Identity\Contracts\Data\DocumentSide;
use App\Modules\Identity\Contracts\Data\VerificationRequest;
use App\Modules\Identity\Contracts\Data\VerificationResult;
use App\Modules\Identity\Contracts\Data\VerificationSession;
use App\Modules\Identity\Contracts\Data\VerificationStatus;
use App\Modules\Identity\Contracts\IdentityVerifier;
use App\Support\Gateways\FakeDriver;
use DateTimeImmutable;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Local and test stand-in for the KYC provider.
 *
 * The outcome of each new session is the scripted one (willReturn) or else config
 * fanfolio.fake.kyc_outcome: pass, fail, review, underage or fraud.
 * State lives in the cache so it survives across requests and Octane workers.
 */
final class FakeIdentityVerifier implements FakeDriver, IdentityVerifier
{
    public const array OUTCOMES = ['pass', 'fail', 'review', 'underage', 'fraud'];

    /** A 1x1 transparent PNG standing in for a document image. */
    private const string PLACEHOLDER_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

    private ?string $scripted = null;

    public function __construct(
        private readonly Cache $cache,
        private readonly Config $config,
    ) {}

    /**
     * Script the outcome of the next session started.
     */
    public function willReturn(string $outcome): self
    {
        if (! in_array($outcome, self::OUTCOMES, true)) {
            throw new RuntimeException("Unknown fake KYC outcome [{$outcome}].");
        }

        $this->scripted = $outcome;

        return $this;
    }

    public function startSession(VerificationRequest $request): VerificationSession
    {
        $ref = 'fake_kyc_'.Str::ulid()->toBase32();
        $configured = $this->config->get('fanfolio.fake.kyc_outcome', 'pass');
        $outcome = $this->scripted ?? (is_string($configured) ? $configured : 'pass');
        $this->scripted = null;

        $this->cache->put($this->key($ref), $outcome, now()->addDay());

        return new VerificationSession($ref, $request->returnUrl.(str_contains($request->returnUrl, '?') ? '&' : '?').'ref='.$ref);
    }

    public function result(string $providerRef): VerificationResult
    {
        $outcome = $this->cache->get($this->key($providerRef));

        if (! is_string($outcome)) {
            throw new RuntimeException("Unknown fake KYC session [{$providerRef}].");
        }

        $now = new DateTimeImmutable;
        $adultDob = $now->modify('-30 years');

        return match ($outcome) {
            'pass' => new VerificationResult($providerRef, VerificationStatus::Passed, true, true, $now, $adultDob, 'TEST PERSON', 'national_id', '0000', $now->modify('+5 years')),
            'review' => new VerificationResult($providerRef, VerificationStatus::Review, true, true, $now, $adultDob, 'TEST PERSON', 'national_id', '0000', $now->modify('+5 years'), failureReason: 'name_mismatch'),
            'fail' => new VerificationResult($providerRef, VerificationStatus::Failed, false, false, $now, failureReason: 'document_unreadable'),
            'underage' => new VerificationResult($providerRef, VerificationStatus::Failed, true, false, $now, $now->modify('-17 years'), 'TEST PERSON', 'national_id', '0000', failureReason: 'under_18'),
            'fraud' => new VerificationResult($providerRef, VerificationStatus::Failed, false, false, $now, suspectedFraud: true, failureReason: 'document_fraud'),
            default => throw new RuntimeException("Unknown fake KYC outcome [{$outcome}]."),
        };
    }

    public function fetchDocumentImage(string $providerRef, DocumentSide $side): DocumentImage
    {
        $this->result($providerRef);

        return new DocumentImage('image/png', base64_decode(self::PLACEHOLDER_PNG, true));
    }

    private function key(string $ref): string
    {
        return 'fake:kyc:'.$ref;
    }
}
