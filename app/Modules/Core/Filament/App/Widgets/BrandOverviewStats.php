<?php

declare(strict_types=1);

namespace App\Modules\Core\Filament\App\Widgets;

use App\Modules\Auth\Models\User;
use App\Modules\Brands\Models\Brand;
use App\Modules\Core\Enums\BrandRole;
use Filament\Facades\Filament;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

final class BrandOverviewStats extends StatsOverviewWidget
{
    protected static bool $isDiscovered = false;

    protected ?string $pollingInterval = null;

    protected function getStats(): array
    {
        /** @var Brand $brand */
        $brand = Filament::getTenant();
        /** @var User $user */
        $user = auth()->user();

        $role = $user->isGlobalAdmin() ? 'Admin' : ($user->brandRole($brand)?->getLabel() ?? 'Member');

        $stats = [
            Stat::make('Your role here', $role)
                ->description($user->isGlobalAdmin() ? 'You see every brand' : 'In '.$brand->name)
                ->icon(Heroicon::OutlinedShieldCheck),
        ];

        if ($user->isGlobalAdmin() || $user->isManagerIn($brand)) {
            $departments = $brand->departments()->count();
            $agents = $brand->memberships()->where('role', BrandRole::Agent->value)->count();

            $stats[] = Stat::make('Departments', (string) $departments)
                ->description('Teams in this brand')
                ->icon(Heroicon::OutlinedRectangleGroup)
                ->url(url('/b/'.$brand->slug.'/departments'));

            $stats[] = Stat::make('Agents', (string) $agents)
                ->description('People working leads')
                ->icon(Heroicon::OutlinedUserGroup)
                ->url(url('/b/'.$brand->slug.'/members'));
        }

        $stats[] = Stat::make('Leads', '—')
            ->description('Lead collection arrives in week 2')
            ->icon(Heroicon::OutlinedInboxArrowDown)
            ->color('gray');

        return $stats;
    }
}
