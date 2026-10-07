<?php

declare(strict_types=1);

namespace App\Modules\Core\Filament\Admin\Widgets;

use App\Modules\Auth\Models\User;
use App\Modules\Brands\Models\Brand;
use App\Modules\Brands\Models\Membership;
use App\Modules\Core\Enums\BrandRole;
use App\Modules\Departments\Models\Department;
use App\Modules\System\Support\SystemStatus;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

final class AdminOverviewStats extends StatsOverviewWidget
{
    protected static bool $isDiscovered = false;

    protected ?string $pollingInterval = null;

    protected function getStats(): array
    {
        $brands = Brand::query()->where('is_active', true)->count();
        $departments = Department::query()->acrossBrands()->count();
        $managers = Membership::query()->acrossBrands()->where('role', BrandRole::Manager->value)->count();
        $agents = Membership::query()->acrossBrands()->where('role', BrandRole::Agent->value)->count();
        $pending = app(SystemStatus::class)->pendingMigrationCount();
        $user = auth()->user();

        $stats = [
            Stat::make('Active brands', (string) $brands)
                ->description($brands === 0 ? 'Add your first brand to begin' : 'Workspaces collecting leads')
                ->icon(Heroicon::OutlinedBuildingStorefront)
                ->url(url('/admin/brands')),
            Stat::make('Departments', (string) $departments)
                ->description('Teams across all brands')
                ->icon(Heroicon::OutlinedRectangleGroup),
            Stat::make('Managers and Agents', $managers.' / '.$agents)
                ->description('People working leads')
                ->icon(Heroicon::OutlinedUserGroup),
        ];

        if ($user instanceof User && $user->isSuperAdmin()) {
            $stats[] = Stat::make('Pending database updates', (string) $pending)
                ->description($pending > 0 ? 'Open System to apply them' : 'Database is up to date')
                ->descriptionIcon($pending > 0 ? Heroicon::OutlinedExclamationTriangle : Heroicon::OutlinedCheckCircle)
                ->color($pending > 0 ? 'warning' : 'success')
                ->icon(Heroicon::OutlinedCircleStack)
                ->url(url('/admin/system'));
        }

        return $stats;
    }
}
