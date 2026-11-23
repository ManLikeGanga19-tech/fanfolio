<?php

declare(strict_types=1);

namespace App\Modules\Audit\Providers;

use App\Modules\Audit\Actions\DatabaseAuditLog;
use App\Modules\Audit\Contracts\AuditLog;
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

    public function register(): void
    {
        // Scoped: under Octane each request gets its own instance bound to its own Request.
        $this->app->scoped(AuditLog::class, DatabaseAuditLog::class);
    }
}
