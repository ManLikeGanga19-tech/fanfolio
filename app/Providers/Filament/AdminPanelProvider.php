<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\Admin\Overview;
use App\Modules\Creators\Filament\Admin\CreatorApprovals;
use App\Modules\Creators\Filament\Admin\CreatorsDirectory;
use App\Modules\Identity\Http\Middleware\ApplyDisplayTimezone;
use App\Modules\Identity\Http\Middleware\EnsureAdminAccess;
use App\Modules\Identity\Http\Middleware\EnsureDeviceActive;
use App\Modules\TrustSafety\Filament\Admin\ModerationBoard;
use App\Modules\TrustSafety\Filament\Admin\UsersBoard;
use App\Modules\Wallet\Filament\Admin\PayoutReviews;
use Filament\Enums\ThemeMode;
use Filament\FontProviders\LocalFontProvider;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
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
use Illuminate\Support\HtmlString;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * The /admin panel (SEC-15). Sign-in happens on the main log in screen (passkey or
 * password plus second factor); the panel has no login page of its own. The shell and
 * boards follow the canvas Admin* boards (plan docs/plans/M8a.md); each page checks its
 * StaffAbility.
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
            // Inter is self-hosted in resources/css/filament/panels.css.
            ->font('Inter', provider: LocalFontProvider::class)
            // Filament's theme plus the design tokens and self-hosted fonts (no outside font CDN).
            ->viteTheme('resources/css/filament/panels.css')
            ->defaultAvatarProvider(InitialsAvatarProvider::class)
            ->brandLogo(static fn (): HtmlString => new HtmlString(view('filament.brand', ['panel' => 'Admin'])->render()))
            // D-15: the saved time zone, and the script that sends the browser's.
            ->renderHook(PanelsRenderHook::HEAD_END, static fn (): string => Blade::render('<x-ui.timezone-meta />'))
            ->renderHook(PanelsRenderHook::BODY_END, static fn (): string => Blade::render("@vite('resources/js/panel.js')"))
            ->pages([
                // Canvas AdminOverview sidebar order; later boards join as they are built.
                Overview::class,
                CreatorApprovals::class,
                CreatorsDirectory::class,
                ModerationBoard::class,
                PayoutReviews::class,
                UsersBoard::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ApplyDisplayTimezone::class,
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
