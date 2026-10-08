<?php

declare(strict_types=1);

namespace App\Modules\Core\Livewire;

use App\Modules\Activity\Models\Activity;
use App\Modules\Auth\Models\User;
use App\Modules\Brands\Models\Brand;
use App\Modules\Brands\Models\Membership;
use App\Modules\Core\Enums\BrandRole;
use App\Modules\Departments\Models\Department;
use App\Modules\Leads\Models\Lead;
use App\Modules\System\Support\SystemStatus;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Admin overview: brands, teams and people at a glance, leads across all
 * brands this month, and the latest activity. Week 5 turns this into the
 * sales dashboard with brand, department and Agent drill-down.
 */
#[Layout('components.layouts.app')]
#[Title('Overview')]
final class AdminDashboard extends Component
{
    public function render(): View
    {
        /** @var User $user */
        $user = auth()->user();

        $brands = Brand::query()->where('is_active', true)->count();
        $departments = Department::query()->acrossBrands()->count();
        $managers = Membership::query()->acrossBrands()->where('role', BrandRole::Manager->value)->count();
        $agents = Membership::query()->acrossBrands()->where('role', BrandRole::Agent->value)->count();

        $monthStart = now()->startOfMonth();
        $leadsThisMonth = Lead::query()->acrossBrands()->where('created_at', '>=', $monthStart)->count();
        $leadsLastMonth = Lead::query()->acrossBrands()->whereBetween('created_at', [$monthStart->copy()->subMonth(), $monthStart])->count();

        $leadsByBrand = Lead::query()
            ->acrossBrands()
            ->where((new Lead)->qualifyColumn('created_at'), '>=', now()->subDays(30))
            ->join('brands', 'brands.id', '=', 'leads.brand_id')
            ->groupBy('brands.id', 'brands.name', 'brands.colors')
            ->orderByDesc('total')
            ->limit(8)
            ->get([DB::raw('brands.name as name'), DB::raw('brands.colors as colors'), DB::raw('count(*) as total')]);

        $activity = Activity::query()->with(['causer', 'brand'])->latest('id')->limit(8)->get();

        return view('core::livewire.admin-dashboard', [
            'user' => $user,
            'brands' => $brands,
            'departments' => $departments,
            'managers' => $managers,
            'agents' => $agents,
            'leadsThisMonth' => $leadsThisMonth,
            'leadsDelta' => $this->delta($leadsThisMonth, $leadsLastMonth),
            'pendingMigrations' => $user->isSuperAdmin() ? app(SystemStatus::class)->pendingMigrationCount() : null,
            'chart' => [
                'labels' => $leadsByBrand->pluck('name')->all(),
                'values' => $leadsByBrand->pluck('total')->map(fn ($value): int => (int) $value)->all(),
            ],
            'activity' => $activity,
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
