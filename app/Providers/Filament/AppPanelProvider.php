<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Modules\Auth\Http\Middleware\EnsureGlobalAdminHasTwoFactor;
use App\Modules\Auth\Models\User;
use App\Modules\Brands\Models\Brand;
use App\Modules\Core\Http\Middleware\SetPermissionsTeam;
use App\Providers\Filament\Concerns\ConfiguresLiquifyPanel;
use Filament\Actions\Action;
use Filament\Navigation\NavigationGroup;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Icons\Heroicon;

/**
 * /b/{brand}: the brand workspace. Brand is the Filament tenant, so every
 * resource here is scoped to the brand in the URL (layer 1), checked by
 * policies (layer 2) and by permission names (layer 3).
 */
final class AppPanelProvider extends PanelProvider
{
    use ConfiguresLiquifyPanel;

    public function panel(Panel $panel): Panel
    {
        $panel = $this->configureLiquifyPanel($panel, requireTwoFactor: false, extraAuthMiddleware: [EnsureGlobalAdminHasTwoFactor::class])
            ->default()
            ->id('app')
            ->path('b')
            ->tenant(Brand::class, slugAttribute: 'slug')
            ->tenantMenu()
            ->tenantMiddleware([
                SetPermissionsTeam::class,
            ], isPersistent: true)
            ->navigationGroups([
                NavigationGroup::make('Team')->icon(Heroicon::OutlinedUserGroup),
                NavigationGroup::make('Settings')->icon(Heroicon::OutlinedCog6Tooth),
            ])
            ->userMenuItems([
                Action::make('admin')
                    ->label('Admin area')
                    ->icon(Heroicon::OutlinedShieldCheck)
                    ->url('/admin')
                    ->visible(function (): bool {
                        $user = auth()->user();

                        return $user instanceof User && $user->isGlobalAdmin();
                    })
                    ->sort(-10),
            ]);

        return $this->discoverModules($panel, 'App');
    }
}
