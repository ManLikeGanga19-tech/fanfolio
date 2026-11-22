<?php

declare(strict_types=1);

namespace App\Modules\Media\Contracts;

use App\Modules\Media\Contracts\Data\ContentKey;
use App\Modules\Media\Contracts\Data\DrmSystem;
use App\Modules\Media\Contracts\Data\Licence;
use App\Modules\Media\Contracts\Data\LicenceRequest;

/**
 * Multi-DRM service: Widevine, FairPlay, PlayReady (provider not chosen).
 *
 * Only the key id is stored. Key material is used in memory by the packager and discarded.
 * Licences are short-lived and never persistent (SEC-08).
 */
interface DrmLicenseService
{
    public function supports(DrmSystem $system): bool;

    public function createContentKey(string $mediaAssetId): ContentKey;

    public function issueLicence(LicenceRequest $request): Licence;
}
