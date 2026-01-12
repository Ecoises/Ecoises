<?php

namespace App\Providers\Filament;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Facades\FilamentColor;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use BezhanSalleh\FilamentShield\FilamentShieldPlugin;
use DiogoGPinto\AuthUIEnhancer\AuthUIEnhancerPlugin;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->databaseNotifications()
            ->databaseNotificationsPolling('30s')
            ->login()
            ->spa()
            ->colors([
                'primary' => [
                    50 => '#f7fee7',
                    100 => '#ecfccb',
                    200 => '#d9f99d',
                    300 => '#bef264',
                    400 => '#a3e635',
                    500 => '#84cc16',
                    600 => '#65a30d',
                    700 => '#4d7c0f',
                    800 => '#3f6212',
                    900 => '#365314',
                    950 => '#1a2e05',
                ],
                'secondary' => [
                    50 => '#f4f7ee',
                    100 => '#e6ecd9',
                    200 => '#cfdbb8',
                    300 => '#b0c48e',
                    400 => '#92ad66',
                    500 => '#7a9748',
                    600 => '#5e7735',
                    700 => '#4a5d2c',
                    800 => '#3f4e26',
                    900 => '#374424',
                    950 => '#1a2e05',
                ],
                'danger' => Color::Rose,
            ])
            ->brandName('Ecoises')
            ->brandLogo(asset('images/Logo-Ecosises.svg'))
            ->darkModeBrandLogo(asset('images/Logo-Ecosises-Negativo.svg'))
            ->brandLogoHeight('2.5rem')
            ->favicon(asset('images/favicon.png'))
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
                FilamentInfoWidget::class,
            ])
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->plugins([
                FilamentShieldPlugin::make(),
                AuthUIEnhancerPlugin::make()
                ->showEmptyPanelOnMobile(false)
                ->emptyPanelBackgroundImageOpacity('80%')
                ->emptyPanelBackgroundImageUrl(asset('images/login.jpg')),
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
            ->authMiddleware([
                Authenticate::class,
            ]);
            
    }
}
