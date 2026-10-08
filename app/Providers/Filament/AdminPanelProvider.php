<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Providers\Filament\Concerns\ConfiguresLiquifyPanel;
use Filament\Actions\Action;
use Filament\Navigation\NavigationGroup;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Icons\Heroicon;
use Filament\View\PanelsRenderHook;

/**
 * /admin: everything that is not inside one brand. Super Admins and Admins
 * only: brands, admin accounts, integrations, all-brand sales, logs, system.
 */
final class AdminPanelProvider extends PanelProvider
{
    use ConfiguresLiquifyPanel;

    public function panel(Panel $panel): Panel
    {
        $panel = $this->configureLiquifyPanel($panel)
            ->id('admin')
            ->path('admin')
            // Scheduler health in the top bar, next to search (the Hostinger cron drains the queue).
            ->renderHook(PanelsRenderHook::GLOBAL_SEARCH_BEFORE, fn (): string => view('core::filament.topbar-status')->render())
            ->navigationGroups([
                NavigationGroup::make('Settings')->icon(Heroicon::OutlinedCog6Tooth),
                NavigationGroup::make('System')->icon(Heroicon::OutlinedServerStack),
            ])
            ->userMenuItems([
                Action::make('workspace')
                    ->label('Open a brand')
                    ->icon(Heroicon::OutlinedSquares2x2)
                    ->url('/b')
                    ->sort(-10),
            ]);

        return $this->discoverModules($panel, 'Admin');
    }
}
