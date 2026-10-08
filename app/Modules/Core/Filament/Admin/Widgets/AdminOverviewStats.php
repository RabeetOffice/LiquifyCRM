<?php

declare(strict_types=1);

namespace App\Modules\Core\Filament\Admin\Widgets;

use App\Modules\Auth\Models\User;
use App\Modules\Brands\Filament\Admin\Resources\BrandResource;
use App\Modules\Brands\Models\Brand;
use App\Modules\Brands\Models\Membership;
use App\Modules\Core\Enums\BrandRole;
use App\Modules\Core\Filament\Support\LiquifyStat;
use App\Modules\Departments\Models\Department;
use App\Modules\System\Support\SystemStatus;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;

final class AdminOverviewStats extends StatsOverviewWidget
{
    protected static bool $isDiscovered = false;

    /** Rendered with the page: one request, no skeleton flash. */
    protected static bool $isLazy = false;

    protected ?string $pollingInterval = null;

    /** @return array<string, int> */
    protected function getColumns(): array
    {
        return ['default' => 1, 'sm' => 2, 'xl' => max(2, min(4, count($this->getCachedStats())))];
    }

    protected function getStats(): array
    {
        $brands = Brand::query()->where('is_active', true)->count();
        $departments = Department::query()->acrossBrands()->count();
        $managers = Membership::query()->acrossBrands()->where('role', BrandRole::Manager->value)->count();
        $agents = Membership::query()->acrossBrands()->where('role', BrandRole::Agent->value)->count();
        $user = auth()->user();

        $stats = [
            LiquifyStat::make('Active brands', (string) $brands)
                ->description($brands === 0 ? 'Add your first brand to begin' : 'Workspaces collecting leads')
                ->icon(Heroicon::OutlinedBuildingStorefront)
                ->url(BrandResource::getUrl())
                ->linkLabel('Manage brands')
                ->hero(),
            LiquifyStat::make('Departments', (string) $departments)
                ->description('Teams across all brands')
                ->icon(Heroicon::OutlinedRectangleGroup),
            LiquifyStat::make('Managers and Agents', $managers.' / '.$agents)
                ->description('People working leads')
                ->icon(Heroicon::OutlinedUserGroup),
        ];

        if ($user instanceof User && $user->isSuperAdmin()) {
            $pending = app(SystemStatus::class)->pendingMigrationCount();

            $stats[] = LiquifyStat::make('Database updates', (string) $pending)
                ->description($pending > 0 ? 'Open System to apply them' : 'Database is up to date')
                ->descriptionIcon($pending > 0 ? Heroicon::OutlinedExclamationTriangle : Heroicon::OutlinedCheckCircle)
                ->descriptionColor($pending > 0 ? 'warning' : 'success')
                ->icon(Heroicon::OutlinedCircleStack)
                ->url(url('/admin/system'))
                ->linkLabel('Open System');
        } else {
            $stats[] = LiquifyStat::make('Leads this month', null)
                ->placeholder('—')
                ->description('Lead collection arrives in week 2')
                ->icon(Heroicon::OutlinedInboxArrowDown);
        }

        return $stats;
    }
}
