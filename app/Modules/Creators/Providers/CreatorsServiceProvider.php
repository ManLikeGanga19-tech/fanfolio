<?php

declare(strict_types=1);

namespace App\Modules\Creators\Providers;

use App\Modules\Creators\Actions\CreatorApprovalQueue;
use App\Modules\Creators\Actions\Eligibility;
use App\Modules\Creators\Console\SendIdExpiryReminders;
use App\Modules\Creators\Contracts\ApprovalQueue;
use App\Modules\Creators\Contracts\CreatorEligibility;
use App\Modules\Creators\Listeners\SuspendBannedCreator;
use App\Modules\TrustSafety\Events\UserBanned;
use App\Support\Modules\ModuleServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;

final class CreatorsServiceProvider extends ModuleServiceProvider
{
    public const array TABLES = [
        'creators',
        'creator_kyc',
        'payout_methods',
    ];

    public static function moduleName(): string
    {
        return 'Creators';
    }

    public function register(): void
    {
        $this->app->bind(CreatorEligibility::class, Eligibility::class);
        $this->app->bind(ApprovalQueue::class, CreatorApprovalQueue::class);
    }

    public function boot(): void
    {
        parent::boot();

        // Plan M8a: a banned account's creator page is suspended.
        Event::listen(UserBanned::class, SuspendBannedCreator::class);

        // SEC-14.
        RateLimiter::for('creator-onboarding', static fn (Request $request): Limit => Limit::perMinute(20)->by('creator-onboarding:'.self::key($request)));
        RateLimiter::for('handle-check', static fn (Request $request): Limit => Limit::perMinute(60)->by('handle-check:'.self::key($request)));
        // Each start opens a paid session with the KYC provider.
        RateLimiter::for('creator-kyc', static fn (Request $request): Limit => Limit::perHour(10)->by('creator-kyc:'.self::key($request)));

        if ($this->app->runningInConsole()) {
            $this->commands([SendIdExpiryReminders::class]);
        }

        $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
            $schedule->command('creators:id-expiry-reminders')->dailyAt('09:00')->onOneServer()->withoutOverlapping();
        });
    }

    private static function key(Request $request): string
    {
        $id = $request->user()?->getAuthIdentifier();

        return is_string($id) ? $id : (string) $request->ip();
    }
}
