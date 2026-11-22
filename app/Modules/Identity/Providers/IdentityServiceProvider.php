<?php

declare(strict_types=1);

namespace App\Modules\Identity\Providers;

use App\Modules\Identity\Contracts\IdentityVerifier;
use App\Modules\Identity\Contracts\SmsSender;
use App\Modules\Identity\Gateways\Fake\FakeIdentityVerifier;
use App\Modules\Identity\Gateways\Fake\FakeSmsSender;
use App\Support\Modules\ModuleServiceProvider;

final class IdentityServiceProvider extends ModuleServiceProvider
{
    public const array TABLES = [
        'users',
        'user_devices',
        'sessions',
        'password_reset_tokens',
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
    }
}
