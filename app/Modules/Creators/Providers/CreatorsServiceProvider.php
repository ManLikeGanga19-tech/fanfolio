<?php

declare(strict_types=1);

namespace App\Modules\Creators\Providers;

use App\Support\Modules\ModuleServiceProvider;

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
}
