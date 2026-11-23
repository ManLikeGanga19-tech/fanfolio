<?php

declare(strict_types=1);

namespace App\Modules\Identity\Providers;

use App\Modules\Identity\Contracts\BreachedPasswordChecker;
use App\Modules\Identity\Contracts\IdentityVerifier;
use App\Modules\Identity\Contracts\SmsSender;
use App\Modules\Identity\Gateways\Fake\FakeBreachedPasswordChecker;
use App\Modules\Identity\Gateways\Fake\FakeIdentityVerifier;
use App\Modules\Identity\Gateways\Fake\FakeSmsSender;
use App\Modules\Identity\Gateways\Hibp\HibpBreachedPasswordChecker;
use App\Modules\Identity\Models\User;
use App\Support\Modules\ModuleServiceProvider;
use App\Support\Security\KeyedHash;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

final class IdentityServiceProvider extends ModuleServiceProvider
{
    public const array TABLES = [
        'users',
        'user_devices',
        'sessions',
        'password_reset_tokens',
        'phone_verifications',
        'age_verifications',
    ];

    public static function moduleName(): string
    {
        return 'Identity';
    }

    public function register(): void
    {
        $this->bindDriver(IdentityVerifier::class, 'kyc', ['fake' => FakeIdentityVerifier::class]);
        $this->bindDriver(SmsSender::class, 'sms', ['fake' => FakeSmsSender::class]);
        $this->bindDriver(BreachedPasswordChecker::class, 'breached_passwords', [
            'fake' => FakeBreachedPasswordChecker::class,
            'hibp' => HibpBreachedPasswordChecker::class,
        ]);

        $this->app->singleton(KeyedHash::class, static fn (): KeyedHash => KeyedHash::fromConfig());
    }

    public function boot(): void
    {
        parent::boot();

        $this->rateLimits();
    }

    /**
     * SEC-14: Redis-backed limits (the cache store) on sign-up, login and phone codes.
     * Responses over the limit are 429 with Retry-After.
     */
    private function rateLimits(): void
    {
        RateLimiter::for('login', static fn (Request $request): array => [
            Limit::perMinute(5)->by('login:'.mb_strtolower($request->string('email')->toString()).'|'.$request->ip()),
            Limit::perMinute(30)->by('login-ip:'.$request->ip()),
        ]);

        RateLimiter::for('signup', static fn (Request $request): Limit => Limit::perHour(10)->by('signup:'.$request->ip()));

        RateLimiter::for('otp-send', static fn (Request $request): array => [
            Limit::perHour(5)->by('otp-send:'.self::userKey($request)),
            Limit::perHour(20)->by('otp-send-ip:'.$request->ip()),
        ]);

        RateLimiter::for('otp-verify', static fn (Request $request): Limit => Limit::perMinute(10)->by('otp-verify:'.self::userKey($request)));
    }

    private static function userKey(Request $request): string
    {
        $user = $request->user();

        return $user instanceof User ? $user->id : (string) $request->ip();
    }
}
