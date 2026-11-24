<?php

declare(strict_types=1);

namespace App\Modules\TrustSafety\Providers;

use App\Modules\TrustSafety\Actions\ModerationQueue;
use App\Modules\TrustSafety\Contracts\LeakCrawler;
use App\Modules\TrustSafety\Contracts\ModerationStats;
use App\Modules\TrustSafety\Gateways\Fake\FakeLeakCrawler;
use App\Support\Modules\ModuleServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

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
        $this->app->bind(ModerationStats::class, ModerationQueue::class);
    }

    public function boot(): void
    {
        parent::boot();

        // SEC-14: reports are cheap to send and costly to review.
        RateLimiter::for('reports', static function (Request $request): Limit {
            $id = $request->user()?->getAuthIdentifier();

            return Limit::perHour(20)->by('reports:'.(is_string($id) ? $id : (string) $request->ip()));
        });
    }
}
