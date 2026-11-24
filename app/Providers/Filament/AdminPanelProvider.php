<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Modules\Creators\Filament\Admin\CreatorApprovals;
use App\Modules\Identity\Http\Middleware\EnsureAdminAccess;
use App\Modules\Identity\Http\Middleware\EnsureDeviceActive;
use Filament\Enums\ThemeMode;
use Filament\FontProviders\LocalFontProvider;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Blade;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * The /admin panel (SEC-15). Sign-in happens on the main log in screen (passkey or
 * password plus second factor); the panel has no login page of its own. Boards from
 * the canvas arrive in M8.
 */
final class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path(config()->string('fanfolio.admin.path'))
            ->brandName(config()->string('fanfolio.brand.name').' Admin')
            ->defaultThemeMode(ThemeMode::Dark)
            ->colors([
                'primary' => Color::hex(config()->string('fanfolio.design.brand')),
                'danger' => Color::hex(config()->string('fanfolio.design.danger')),
                'warning' => Color::hex(config()->string('fanfolio.design.warning')),
                'success' => Color::hex(config()->string('fanfolio.design.success')),
            ])
            // Inter is self-hosted (resources/css/admin.css), so no outside font CDN is called.
            ->font('Inter', provider: LocalFontProvider::class)
            ->renderHook(PanelsRenderHook::HEAD_END, static fn (): string => Blade::render("@vite('resources/css/admin.css')"))
            ->pages([
                Dashboard::class,
                // Decision D-10: minimal until the M8 approvals board.
                CreatorApprovals::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            // Filament's own Authenticate is left out on purpose: Laravel's middleware priority
            // would run it ahead of EnsureAdminAccess and refuse staff without an audit entry.
            // EnsureAdminAccess covers guests, staff role, MFA, IP allow-list and SSO.
            ->authMiddleware([
                // A session whose device was signed out ends here first.
                EnsureDeviceActive::class,
                EnsureAdminAccess::class,
            ]);
    }
}
