<?php

declare(strict_types=1);

namespace App\Modules\Content\Providers;

use App\Support\Modules\ModuleServiceProvider;

final class ContentServiceProvider extends ModuleServiceProvider
{
    public const array TABLES = [
        'posts',
        'media_items',
        'post_media',
        'vault_folders',
    ];

    public static function moduleName(): string
    {
        return 'Content';
    }
}
