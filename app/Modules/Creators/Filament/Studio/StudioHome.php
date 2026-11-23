<?php

declare(strict_types=1);

namespace App\Modules\Creators\Filament\Studio;

use BackedEnum;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Support\Icons\Heroicon;

/**
 * The /studio panel shell. Posts, prices, earnings and payouts arrive with their
 * milestones (M4, M5, M3). Only approved creators get here (EnsureStudioAccess).
 */
final class StudioHome extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHome;

    protected static ?string $navigationLabel = 'Home';

    protected static ?string $title = 'Welcome to Studio';

    protected static ?string $slug = 'home';

    protected string $view = 'creators::filament.studio-home';

    /** The panel's landing page: /studio itself. */
    public static function getRoutePath(Panel $panel): string
    {
        return '/';
    }
}
