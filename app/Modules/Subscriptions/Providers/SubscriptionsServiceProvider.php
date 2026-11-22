<?php

declare(strict_types=1);

namespace App\Modules\Subscriptions\Providers;

use App\Support\Modules\ModuleServiceProvider;

final class SubscriptionsServiceProvider extends ModuleServiceProvider
{
    public const array TABLES = [
        'subscription_plans',
        'subscriptions',
        'entitlements',
    ];

    public static function moduleName(): string
    {
        return 'Subscriptions';
    }
}
