<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Providers;

use App\Support\Modules\ModuleServiceProvider;

final class NotificationsServiceProvider extends ModuleServiceProvider
{
    public const array TABLES = [
        'notifications',
        'push_subscriptions',
    ];

    public static function moduleName(): string
    {
        return 'Notifications';
    }
}
