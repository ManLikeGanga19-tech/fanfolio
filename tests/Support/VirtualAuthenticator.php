<?php

declare(strict_types=1);

namespace Tests\Support;

use OpenSSLAsymmetricKey;
use RuntimeException;

/**
 * A software passkey for tests: a real P-256 key pair, a "none" attestation and
 * ECDSA-signed assertions, encoded the way the browser and resources/js/passkeys.js
 * send them. Lets feature tests run full WebAuthn ceremonies without a browser.
 */
final class VirtualAuthenticator
{
    private readonly OpenSSLAsymmetricKey $key;

    private readonly string $credentialId;

    private string $userHandle = '';

    private int $counter = 0;

    public function __construct(private readonly string $origin, private readonly string $rpId)
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);

        if ($key === false) {
            throw new RuntimeException('Could not create a test key.');
        }

        $this->key = $key;
        $this->credentialId = random_bytes(32);
    }

    public static function forApp(): self
    {
        $url = (string) config('app.url');
        $host = (string) parse_url($url, PHP_URL_HOST);

        return new self(rtrim($url, '/'), (string) (config('webauthn.relying_party.id') ?: $host));
    }

    public function id(): string
    {
        return $this->base64Url($this->credentialId);
    }

    /**
     * Response to navigator.credentials.create() for the given server options.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function attest(array $options): array
    {
        $user = $options['user'] ?? [];
        $this->userHandle = is_array($user) && is_string($user['id'] ?? null) ? $user['id'] : '';

        $clientData = $this->clientData('webauthn.create', (string) $options['challenge']);

        $authData = hash('sha256', $this->rpId, true)
            .chr(0x45) // user present, user verified, attested credential data
            .pack('N', $this->counter)
            .str_repeat("\0", 16) // AAGUID
            .pack('n', strlen($this->credentialId))
            .$this->credentialId
            .$this->coseKey();

        $attestationObject = $this->cborMap([
            'fmt' => $this->cborText('none'),
            'attStmt' => $this->cborMap([]),
            'authData' => $this->cborBytes($authData),
        ]);

        return [
            'id' => $this->id(),
            'rawId' => base64_encode($this->credentialId),
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => base64_encode($clientData),
                'attestationObject' => base64_encode($attestationObject),
            ],
        ];
    }

    /**
     * Response to navigator.credentials.get() for the given server options.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function assert(array $options, bool $userVerified = true): array
    {
        $this->counter++;

        $clientData = $this->clientData('webauthn.get', (string) $options['challenge']);
        $authData = hash('sha256', $this->rpId, true)
            .chr($userVerified ? 0x05 : 0x01)
            .pack('N', $this->counter);

        openssl_sign($authData.hash('sha256', $clientData, true), $signature, $this->key, OPENSSL_ALGO_SHA256);

        return [
            'id' => $this->id(),
            'rawId' => base64_encode($this->credentialId),
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => base64_encode($clientData),
                'authenticatorData' => base64_encode($authData),
                'signature' => base64_encode((string) $signature),
                'userHandle' => $this->userHandle,
            ],
        ];
    }

    private function clientData(string $type, string $challenge): string
    {
        return (string) json_encode([
            'type' => $type,
            'challenge' => $challenge,
            'origin' => $this->origin,
            'crossOrigin' => false,
        ], JSON_UNESCAPED_SLASHES);
    }

    private function coseKey(): string
    {
        $details = openssl_pkey_get_details($this->key);
        $x = str_pad((string) ($details['ec']['x'] ?? ''), 32, "\0", STR_PAD_LEFT);
        $y = str_pad((string) ($details['ec']['y'] ?? ''), 32, "\0", STR_PAD_LEFT);

        return $this->cborHeader(5, 5)
            .$this->cborInt(1).$this->cborInt(2)    // kty: EC2
            .$this->cborInt(3).$this->cborInt(-7)   // alg: ES256
            .$this->cborInt(-1).$this->cborInt(1)   // crv: P-256
            .$this->cborInt(-2).$this->cborBytes($x)
            .$this->cborInt(-3).$this->cborBytes($y);
    }

    private function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private function cborHeader(int $major, int $length): string
    {
        $type = $major << 5;

        return match (true) {
            $length < 24 => chr($type | $length),
            $length < 0x100 => chr($type | 24).chr($length),
            $length < 0x10000 => chr($type | 25).pack('n', $length),
            default => chr($type | 26).pack('N', $length),
        };
    }

    private function cborInt(int $value): string
    {
        return $value >= 0 ? $this->cborHeader(0, $value) : $this->cborHeader(1, -1 - $value);
    }

    private function cborBytes(string $bytes): string
    {
        return $this->cborHeader(2, strlen($bytes)).$bytes;
    }

    private function cborText(string $text): string
    {
        return $this->cborHeader(3, strlen($text)).$text;
    }

    /**
     * @param  array<string, string>  $entries  already-encoded values
     */
    private function cborMap(array $entries): string
    {
        $out = $this->cborHeader(5, count($entries));
        foreach ($entries as $key => $value) {
            $out .= $this->cborText($key).$value;
        }

        return $out;
    }
}
