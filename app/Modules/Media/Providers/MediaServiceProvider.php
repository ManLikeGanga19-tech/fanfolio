<?php

declare(strict_types=1);

namespace App\Modules\Media\Providers;

use App\Modules\Media\Contracts\AbuseHashMatcher;
use App\Modules\Media\Contracts\ContentClassifier;
use App\Modules\Media\Contracts\DrmLicenseService;
use App\Modules\Media\Contracts\MalwareScanner;
use App\Modules\Media\Gateways\Fake\FakeAbuseHashMatcher;
use App\Modules\Media\Gateways\Fake\FakeContentClassifier;
use App\Modules\Media\Gateways\Fake\FakeDrmLicenseService;
use App\Modules\Media\Gateways\Fake\FakeMalwareScanner;
use App\Support\Modules\ModuleServiceProvider;

final class MediaServiceProvider extends ModuleServiceProvider
{
    public const array TABLES = [
        'media_assets',
        'media_renditions',
        'drm_keys',
        'playback_sessions',
    ];

    public static function moduleName(): string
    {
        return 'Media';
    }

    public function register(): void
    {
        $this->bindDriver(DrmLicenseService::class, 'drm', ['fake' => FakeDrmLicenseService::class]);
        $this->bindDriver(MalwareScanner::class, 'malware', ['fake' => FakeMalwareScanner::class]);
        $this->bindDriver(AbuseHashMatcher::class, 'abuse_hash', ['fake' => FakeAbuseHashMatcher::class]);
        $this->bindDriver(ContentClassifier::class, 'classifier', ['fake' => FakeContentClassifier::class]);
    }
}
