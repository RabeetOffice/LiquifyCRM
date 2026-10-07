<?php

declare(strict_types=1);

namespace App\Modules\Core\Filament\App\Pages;

use App\Modules\Core\Filament\App\Widgets\BrandOverviewStats;
use App\Modules\Core\Filament\App\Widgets\SetupChecklistWidget;
use BackedEnum;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Support\Icons\Heroicon;

/**
 * Brand dashboard. Week 2 adds the New / Unassigned queue and lead cards;
 * for now it carries the setup checklist and the team overview.
 */
final class Dashboard extends BaseDashboard
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHome;

    protected static ?int $navigationSort = -10;

    public function getWidgets(): array
    {
        return [
            SetupChecklistWidget::class,
            BrandOverviewStats::class,
        ];
    }

    public function getColumns(): int
    {
        return 1;
    }
}
