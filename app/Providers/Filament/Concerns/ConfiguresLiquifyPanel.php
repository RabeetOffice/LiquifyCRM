<?php

declare(strict_types=1);

namespace App\Providers\Filament\Concerns;

use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Enums\ThemeMode;
use Filament\FontProviders\LocalFontProvider;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * Everything the two panels share: Liquify theme, auth pages, forced 2FA for
 * global admins, SPA mode, notifications and the standard middleware stack.
 */
trait ConfiguresLiquifyPanel
{
    /**
     * @param  list<class-string>  $extraAuthMiddleware
     */
    protected function configureLiquifyPanel(Panel $panel, bool $requireTwoFactor = true, array $extraAuthMiddleware = []): Panel
    {
        return $panel
            ->login()
            ->passwordReset()
            ->profile(isSimple: false)
            // Filament registers the "set up required" page only when this flag is
            // true at boot, so the admin panel requires it for everyone (only Super
            // Admins and Admins can enter it) and the brand panel enforces the same
            // rule for global admins through EnsureGlobalAdminHasTwoFactor.
            ->multiFactorAuthentication(
                [
                    AppAuthentication::make()
                        ->recoverable()
                        ->regenerableRecoveryCodes()
                        ->brandName('LiquifyCRM'),
                ],
                isRequired: $requireTwoFactor && (bool) config('liquify.require_two_factor'),
            )
            ->colors([
                'primary' => config('liquify.palette.primary'),
                'gray' => Color::Stone,
                'info' => Color::Sky,
                'success' => Color::Green,
                'warning' => Color::Amber,
                'danger' => Color::Rose,
            ])
            // System font stack (SF Pro, Segoe UI, Inter...); no external font requests.
            ->font('system-ui', provider: LocalFontProvider::class)
            ->viteTheme('resources/css/filament/liquify/theme.css')
            ->brandName('LiquifyCRM')
            ->brandLogo(fn () => view('filament.brand-logo'))
            ->brandLogoHeight('2rem')
            ->favicon(asset('favicon.svg'))
            ->renderHook(PanelsRenderHook::HEAD_END, fn (): string => view('core::filament.head-extras')->render())
            ->darkMode()
            ->defaultThemeMode(ThemeMode::System)
            ->spa()
            ->sidebarCollapsibleOnDesktop()
            ->databaseNotifications()
            ->databaseNotificationsPolling('30s')
            // 'mod' is ⌘ on a Mac and Ctrl elsewhere, so the field hint reads ⌘K or CTRL+K.
            ->globalSearchKeyBindings(['mod+k'])
            ->globalSearchFieldKeyBindingSuffix()
            ->unsavedChangesAlerts()
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                ...$extraAuthMiddleware,
            ]);
    }

    /**
     * Register the resources, pages and widgets every module keeps under
     * app/Modules/<Module>/Filament/<Area>.
     */
    protected function discoverModules(Panel $panel, string $area): Panel
    {
        foreach (glob(app_path('Modules/*'), GLOB_ONLYDIR) ?: [] as $directory) {
            $module = basename($directory);
            $base = "{$directory}/Filament/{$area}";
            $namespace = "App\\Modules\\{$module}\\Filament\\{$area}";

            if (is_dir("{$base}/Resources")) {
                $panel->discoverResources(in: "{$base}/Resources", for: "{$namespace}\\Resources");
            }

            if (is_dir("{$base}/Pages")) {
                $panel->discoverPages(in: "{$base}/Pages", for: "{$namespace}\\Pages");
            }

            if (is_dir("{$base}/Widgets")) {
                $panel->discoverWidgets(in: "{$base}/Widgets", for: "{$namespace}\\Widgets");
            }
        }

        return $panel;
    }
}
