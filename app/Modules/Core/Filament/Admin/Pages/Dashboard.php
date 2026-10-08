<?php

declare(strict_types=1);

namespace App\Modules\Core\Filament\Admin\Pages;

use App\Modules\Brands\Filament\Admin\Resources\BrandResource;
use App\Modules\Core\Filament\Admin\Widgets\AdminOverviewStats;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Support\Icons\Heroicon;

/**
 * Admin overview. Week 5 turns this into the sales dashboard with brand,
 * department and Agent drill-down.
 */
final class Dashboard extends BaseDashboard
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHome;

    protected static ?string $navigationLabel = 'Overview';

    protected static ?string $title = 'Overview';

    protected static ?int $navigationSort = -10;

    protected ?string $subheading = 'Here is the summary across all brands.';

    public function getWidgets(): array
    {
        return [
            AdminOverviewStats::class,
        ];
    }

    public function getColumns(): int
    {
        return 1;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('addBrand')
                ->label('Add brand')
                ->icon(Heroicon::OutlinedPlus)
                ->url(fn (): string => BrandResource::getUrl('create'))
                ->visible(fn (): bool => BrandResource::canCreate()),
        ];
    }
}
