<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Modules\Creators\Filament\Studio\StudioHome;
use App\Modules\Creators\Http\Middleware\EnsureStudioAccess;
use App\Modules\Identity\Http\Middleware\ApplyDisplayTimezone;
use App\Modules\Identity\Http\Middleware\EnsureDeviceActive;
use App\Modules\Wallet\Filament\Studio\EarningsPage;
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
 * The creator /studio panel (Filament 4, locked stack). Sign-in happens on the main
 * log in screen; the panel has no login page of its own. Only approved creators with
 * MFA get in (EnsureStudioAccess); everyone else is sent to /creator/status.
 * Posts, prices, earnings and payouts arrive with their milestones.
 */
final class StudioPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('studio')
            ->path(config()->string('fanfolio.studio.path'))
            ->brandName(config()->string('fanfolio.brand.name').' Studio')
            ->defaultThemeMode(ThemeMode::Dark)
            ->colors([
                'primary' => Color::hex(config()->string('fanfolio.design.brand')),
                'danger' => Color::hex(config()->string('fanfolio.design.danger')),
                'warning' => Color::hex(config()->string('fanfolio.design.warning')),
                'success' => Color::hex(config()->string('fanfolio.design.success')),
            ])
            ->font('Inter', provider: LocalFontProvider::class)
            // Filament's theme plus the design tokens and self-hosted fonts (no outside font CDN).
            ->viteTheme('resources/css/filament/panels.css')
            ->defaultAvatarProvider(InitialsAvatarProvider::class)
            ->brandLogo(static fn (): HtmlString => new HtmlString(view('filament.brand', ['panel' => 'Studio'])->render()))
            // D-15: the saved time zone, and the script that sends the browser's.
            ->renderHook(PanelsRenderHook::HEAD_END, static fn (): string => Blade::render('<x-ui.timezone-meta />'))
            ->renderHook(PanelsRenderHook::BODY_END, static fn (): string => Blade::render("@vite('resources/js/panel.js')"))
            ->pages([
                StudioHome::class,
                EarningsPage::class,
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
            // As in the admin panel, Filament's own Authenticate is left out: EnsureStudioAccess
            // covers guests, MFA and creator eligibility, and sends refusals somewhere useful.
            ->authMiddleware([
                EnsureDeviceActive::class,
                EnsureStudioAccess::class,
            ]);
    }
}
