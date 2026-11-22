<?php

declare(strict_types=1);

namespace App\Modules\Collaborations\Providers;

use App\Support\Modules\ModuleServiceProvider;

final class CollaborationsServiceProvider extends ModuleServiceProvider
{
    public const array TABLES = [
        'post_participants',
        'participant_consents',
        'collaborator_profiles',
    ];

    public static function moduleName(): string
    {
        return 'Collaborations';
    }
}
