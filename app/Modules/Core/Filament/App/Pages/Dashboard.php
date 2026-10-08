<?php

declare(strict_types=1);

namespace App\Modules\Core\Filament\App\Pages;

use App\Modules\Brands\Filament\App\Resources\MemberResource;
use App\Modules\Brands\Models\Brand;
use App\Modules\Core\Filament\App\Widgets\BrandOverviewStats;
use App\Modules\Core\Filament\App\Widgets\SetupChecklistWidget;
use BackedEnum;
use Filament\Facades\Filament;
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

    public function getSubheading(): ?string
    {
        $brand = Filament::getTenant();

        return $brand instanceof Brand ? 'Here is the summary for '.$brand->name.'.' : null;
    }

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

    protected function getHeaderActions(): array
    {
        return [
            // The same modal as the Members screen: one primary (orange) button per screen.
            MemberResource::inviteAction()
                ->visible(fn (): bool => MemberResource::canCreate()),
        ];
    }
}
