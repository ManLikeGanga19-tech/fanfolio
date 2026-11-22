<?php

declare(strict_types=1);

namespace App\Modules\Audit\Providers;

use App\Support\Modules\ModuleServiceProvider;

final class AuditServiceProvider extends ModuleServiceProvider
{
    public const array TABLES = [
        'audit_log',
    ];

    public static function moduleName(): string
    {
        return 'Audit';
    }
}
