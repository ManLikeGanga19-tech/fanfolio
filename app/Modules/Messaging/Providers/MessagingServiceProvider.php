<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Providers;

use App\Support\Modules\ModuleServiceProvider;

final class MessagingServiceProvider extends ModuleServiceProvider
{
    public const array TABLES = [
        'conversations',
        'messages',
        'message_unlocks',
    ];

    public static function moduleName(): string
    {
        return 'Messaging';
    }
}
