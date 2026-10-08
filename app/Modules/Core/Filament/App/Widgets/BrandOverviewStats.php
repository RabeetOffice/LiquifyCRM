<?php

declare(strict_types=1);

namespace App\Modules\Core\Filament\App\Widgets;

use App\Modules\Auth\Models\User;
use App\Modules\Brands\Models\Brand;
use App\Modules\Core\Enums\BrandRole;
use App\Modules\Core\Filament\Support\LiquifyStat;
use Filament\Facades\Filament;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;

/**
 * Brand overview cards. The first card is the hero; in week 2 it becomes
 * the New / Unassigned lead count.
 */
final class BrandOverviewStats extends StatsOverviewWidget
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
        /** @var Brand $brand */
        $brand = Filament::getTenant();
        /** @var User $user */
        $user = auth()->user();

        $role = $user->isGlobalAdmin() ? 'Admin' : ($user->brandRole($brand)?->getLabel() ?? 'Member');

        $roleStat = LiquifyStat::make('Your role here', $role)
            ->description($user->isGlobalAdmin() ? 'You see every brand' : 'In '.$brand->name)
            ->icon(Heroicon::OutlinedShieldCheck);

        $stats = [];

        if ($user->isGlobalAdmin() || $user->isManagerIn($brand)) {
            $departments = $brand->departments()->count();
            $agents = $brand->memberships()->where('role', BrandRole::Agent->value)->count();

            $stats[] = LiquifyStat::make('Agents', (string) $agents)
                ->description($agents === 0 ? 'Invite the people who work leads' : 'People working leads')
                ->icon(Heroicon::OutlinedUserGroup)
                ->url(url('/b/'.$brand->slug.'/members'))
                ->linkLabel('Manage team')
                ->hero();

            $stats[] = LiquifyStat::make('Departments', (string) $departments)
                ->description($departments === 0 ? 'Create your first team' : 'Teams in this brand')
                ->icon(Heroicon::OutlinedRectangleGroup)
                ->url(url('/b/'.$brand->slug.'/departments'))
                ->linkLabel('View departments');

            $stats[] = $roleStat;
        } else {
            $stats[] = $roleStat->hero();
        }

        $stats[] = LiquifyStat::make('Leads this month', null)
            ->placeholder('—')
            ->description('Lead collection arrives in week 2')
            ->icon(Heroicon::OutlinedInboxArrowDown);

        return $stats;
    }
}
