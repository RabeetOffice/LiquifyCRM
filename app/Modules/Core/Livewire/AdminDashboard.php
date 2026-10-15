<?php

declare(strict_types=1);

namespace App\Modules\Core\Livewire;

use App\Modules\Activity\Models\Activity;
use App\Modules\Auth\Models\User;
use App\Modules\Brands\Models\Brand;
use App\Modules\Core\Support\BrandsOverview;
use App\Modules\System\Support\SystemUpdater;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Admin overview: what is worth comparing across brands. This month's leads
 * and sales, what waits for somebody (unassigned leads, overdue follow-ups),
 * every brand side by side in its own currency, the latest activity, and
 * the installed version at the bottom.
 */
#[Layout('components.layouts.app')]
#[Title('Overview')]
final class AdminDashboard extends Component
{
    public function render(): View
    {
        /** @var User $user */
        $user = auth()->user();

        $overview = BrandsOverview::make();
        $rows = collect($overview->rows());

        $leads = (int) $rows->sum('leads');
        $sales = (int) $rows->sum('sales');
        $upsells = (int) $rows->sum('upsells');

        return view('core::livewire.admin-dashboard', [
            'user' => $user,
            'brands' => Brand::query()->where('is_active', true)->count(),
            'rows' => $rows->map(fn (array $row): array => [...$row, 'delta' => $this->delta($row['leads'], $row['previous'])])->all(),
            'leadsThisMonth' => $leads,
            'leadsDelta' => $this->delta($leads, (int) $rows->sum('previous')),
            'sales' => $sales,
            'salesSplit' => $upsells > 0 ? ($sales - $upsells).' front, '.$upsells.' '.str('upsell')->plural($upsells) : null,
            'unassigned' => (int) $rows->sum('unassigned'),
            'overdue' => (int) $rows->sum('overdue'),
            'update' => SystemUpdater::flag(),
            'chart' => $overview->lastThirtyDays(),
            'activity' => Activity::query()->with(['causer', 'brand'])->latest('id')->limit(8)->get(),
        ]);
    }

    /** @return array{text: string, up: ?bool}|null */
    private function delta(int $current, int $previous): ?array
    {
        if ($previous === 0 && $current === 0) {
            return null;
        }

        if ($previous === 0) {
            return ['text' => 'new', 'up' => true];
        }

        $change = (int) round(($current - $previous) / $previous * 100);

        return ['text' => ($change >= 0 ? '+' : '').$change.'%', 'up' => $change >= 0];
    }
}
