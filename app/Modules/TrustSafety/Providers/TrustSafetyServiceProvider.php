<?php

declare(strict_types=1);

namespace App\Modules\TrustSafety\Providers;

use App\Modules\TrustSafety\Contracts\LeakCrawler;
use App\Modules\TrustSafety\Gateways\Fake\FakeLeakCrawler;
use App\Support\Modules\ModuleServiceProvider;

final class TrustSafetyServiceProvider extends ModuleServiceProvider
{
    public const array TABLES = [
        'reports',
        'moderation_cases',
        'strikes',
        'takedowns',
    ];

    public static function moduleName(): string
    {
        return 'TrustSafety';
    }

    public function register(): void
    {
        $this->bindDriver(LeakCrawler::class, 'leak_crawler', ['fake' => FakeLeakCrawler::class]);
    }
}
