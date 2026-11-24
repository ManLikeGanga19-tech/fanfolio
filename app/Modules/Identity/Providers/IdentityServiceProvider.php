<?php

declare(strict_types=1);

namespace App\Modules\Identity\Providers;

use App\Modules\Creators\Events\CreatorApproved;
use App\Modules\Identity\Actions\AccountLookup;
use App\Modules\Identity\Actions\PhoneHandoffs;
use App\Modules\Identity\Actions\SignUpStats;
use App\Modules\Identity\Console\MakeDevStaff;
use App\Modules\Identity\Contracts\Accounts;
use App\Modules\Identity\Contracts\AccountStats;
use App\Modules\Identity\Contracts\BreachedPasswordChecker;
use App\Modules\Identity\Contracts\IdentityVerifier;
use App\Modules\Identity\Contracts\PhoneHandoff;
use App\Modules\Identity\Contracts\SmsSender;
use App\Modules\Identity\Contracts\StaffAbility;
use App\Modules\Identity\Gateways\Fake\FakeBreachedPasswordChecker;
use App\Modules\Identity\Gateways\Fake\FakeIdentityVerifier;
use App\Modules\Identity\Gateways\Fake\FakeSmsSender;
use App\Modules\Identity\Gateways\Hibp\HibpBreachedPasswordChecker;
use App\Modules\Identity\Http\Middleware\ApplyDisplayTimezone;
use App\Modules\Identity\Http\Middleware\EnsureAdminAccess;
use App\Modules\Identity\Http\Middleware\EnsureDeviceActive;
use App\Modules\Identity\Http\Middleware\EnsureMfaEnrolled;
use App\Modules\Identity\Http\Middleware\EnsureRecentStepUp;
use App\Modules\Identity\Listeners\GrantCreatorRole;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Models\UserDevice;
use App\Modules\Identity\Policies\UserDevicePolicy;
use App\Support\Modules\ModuleServiceProvider;
use App\Support\Security\KeyedHash;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;

final class IdentityServiceProvider extends ModuleServiceProvider
{
    public const array TABLES = [
        'users',
        'user_devices',
        'sessions',
        'password_reset_tokens',
        // Unused since D-12 (sign-up verifies email); kept until the owner agrees to drop it.
        'phone_verifications',
        'email_verifications',
        'webauthn_credentials',
        'mfa_totp',
        'mfa_recovery_codes',
        'age_verifications',
    ];

    public static function moduleName(): string
    {
        return 'Identity';
    }

    public function register(): void
    {
        $this->bindDriver(IdentityVerifier::class, 'kyc', ['fake' => FakeIdentityVerifier::class]);
        $this->app->bind(PhoneHandoff::class, PhoneHandoffs::class);
        $this->bindDriver(SmsSender::class, 'sms', ['fake' => FakeSmsSender::class]);
        $this->bindDriver(BreachedPasswordChecker::class, 'breached_passwords', [
            'fake' => FakeBreachedPasswordChecker::class,
            'hibp' => HibpBreachedPasswordChecker::class,
        ]);

        $this->app->bind(Accounts::class, AccountLookup::class);
        $this->app->bind(AccountStats::class, SignUpStats::class);
        $this->app->singleton(KeyedHash::class, static fn (): KeyedHash => KeyedHash::fromConfig());
    }

    public function boot(): void
    {
        // Registered before the module routes load in parent::boot().
        $router = $this->app->make(Router::class);
        $router->aliasMiddleware('step-up', EnsureRecentStepUp::class);
        // Staff-only pages outside the Filament panel (for example the ID viewer, D-01).
        $router->aliasMiddleware('staff', EnsureAdminAccess::class);
        // SEC-05 and SEC-04 apply to every browser page, whichever module serves it.
        $router->pushMiddlewareToGroup('web', EnsureDeviceActive::class);
        $router->pushMiddlewareToGroup('web', EnsureMfaEnrolled::class);

        Gate::policy(UserDevice::class, UserDevicePolicy::class);

        // SEC-15: one Gate per staff ability (the role matrix in docs/plans/M8a.md).
        foreach (StaffAbility::cases() as $ability) {
            Gate::define($ability->value, static fn (User $user): bool => $user->canStaff($ability));
        }

        // D-15: times on every page in the signed-in user's own time zone.
        $router->pushMiddlewareToGroup('web', ApplyDisplayTimezone::class);

        // An approved creator gets the creator role (opens Studio).
        Event::listen(CreatorApproved::class, GrantCreatorRole::class);

        parent::boot();

        $this->rateLimits();

        if ($this->app->runningInConsole()) {
            $this->commands([MakeDevStaff::class]);
        }
    }

    /**
     * SEC-14: Redis-backed limits (the cache store) on sign-up, login and email codes.
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

        RateLimiter::for('passkey', static fn (Request $request): Limit => Limit::perMinute(20)->by('passkey:'.$request->ip()));

        // Second factor at login and step-up: per pending or signed-in account, and per address.
        RateLimiter::for('mfa', static fn (Request $request): array => [
            Limit::perMinute(5)->by('mfa:'.self::mfaKey($request)),
            Limit::perMinute(20)->by('mfa-ip:'.$request->ip()),
        ]);

        RateLimiter::for('age-check', static fn (Request $request): Limit => Limit::perHour(5)->by('age-check:'.self::userKey($request)));

        RateLimiter::for('password-reset', static fn (Request $request): array => [
            Limit::perHour(5)->by('password-reset:'.mb_strtolower($request->string('email')->toString())),
            Limit::perHour(20)->by('password-reset-ip:'.$request->ip()),
        ]);

        // D-14: phone links for the ID and face scan, per address.
        RateLimiter::for('phone-handoff', static fn (Request $request): Limit => Limit::perMinute(20)->by('phone-handoff:'.$request->ip()));

        RateLimiter::for('otp-verify', static fn (Request $request): Limit => Limit::perMinute(10)->by('otp-verify:'.self::userKey($request)));

        // D-15: the browser reports its time zone when it differs from the saved one.
        RateLimiter::for('timezone', static fn (Request $request): Limit => Limit::perHour(20)->by('timezone:'.self::userKey($request)));
    }

    private static function mfaKey(Request $request): string
    {
        $pending = $request->session()->get('identity.mfa_pending');

        if (is_array($pending) && is_string($pending['user'] ?? null)) {
            return $pending['user'];
        }

        return self::userKey($request);
    }

    private static function userKey(Request $request): string
    {
        $user = $request->user();

        return $user instanceof User ? $user->id : (string) $request->ip();
    }
}
