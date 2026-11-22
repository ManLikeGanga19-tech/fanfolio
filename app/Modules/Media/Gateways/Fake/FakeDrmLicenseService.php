<?php

declare(strict_types=1);

namespace App\Modules\Media\Gateways\Fake;

use App\Modules\Media\Contracts\Data\ContentKey;
use App\Modules\Media\Contracts\Data\DrmSystem;
use App\Modules\Media\Contracts\Data\Licence;
use App\Modules\Media\Contracts\Data\LicenceRequest;
use App\Modules\Media\Contracts\DrmLicenseService;
use App\Support\Gateways\FakeDriver;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;
use RuntimeException;

/**
 * Clear-key DRM for local development and tests only (brief: "local playback with clear-key in dev only").
 *
 * Keys are derived from the key id and the app key, so the fake stores nothing and
 * any worker can answer a licence request.
 */
final readonly class FakeDrmLicenseService implements DrmLicenseService, FakeDriver
{
    public function __construct(
        Application $app,
        private Config $config,
    ) {
        if (! $app->environment('local', 'testing')) {
            throw new RuntimeException('Clear-key DRM is allowed only in local and testing environments.');
        }
    }

    public function supports(DrmSystem $system): bool
    {
        return $system === DrmSystem::ClearKey;
    }

    public function createContentKey(string $mediaAssetId): ContentKey
    {
        $keyId = bin2hex(random_bytes(16));

        return new ContentKey($keyId, $this->deriveKey($keyId));
    }

    public function issueLicence(LicenceRequest $request): Licence
    {
        if (! $this->supports($request->system)) {
            throw new RuntimeException("The fake DRM service only issues clear-key licences, not {$request->system->value}.");
        }

        $keys = array_map(fn (string $keyId): array => [
            'kty' => 'oct',
            'kid' => $this->base64Url((string) hex2bin($keyId)),
            'k' => $this->base64Url((string) hex2bin($this->deriveKey($keyId))),
        ], $request->keyIds);

        return new Licence(json_encode(['keys' => $keys, 'type' => 'temporary'], JSON_THROW_ON_ERROR), 'application/json');
    }

    private function deriveKey(string $keyId): string
    {
        $appKey = $this->config->get('app.key');

        return substr(hash_hmac('sha256', 'fanfolio-clearkey:'.$keyId, is_string($appKey) ? $appKey : ''), 0, 32);
    }

    private function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
