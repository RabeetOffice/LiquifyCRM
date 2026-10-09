<?php

declare(strict_types=1);

namespace App\Modules\Sales\Livewire;

use App\Modules\Auth\Models\User;
use App\Modules\Brands\Models\Brand;
use App\Modules\Departments\Models\Department;
use App\Modules\Sales\Support\SalesReport;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The sales dashboard. In the admin area it shows one brand at a time in
 * that brand's own currency (pick the brand from the cards on top) and
 * drills down to a department and its Agents. Inside a brand, a Manager
 * sees their departments and an Agent their own target and sales. Every
 * scope gets the same charts: revenue trend and where the leads came from.
 */
#[Layout('components.layouts.app')]
final class SalesDashboard extends Component
{
    public ?Brand $brand = null;

    #[Url(except: '')]
    public string $period = '';

    /** Admin drill-down: one brand. */
    #[Url(as: 'b', except: '')]
    public string $brandFilter = '';

    #[Url(as: 'd', except: '')]
    public string $departmentFilter = '';

    public function mount(?Brand $brand = null): void
    {
        $this->brand = $brand !== null && $brand->exists ? $brand : null;

        abort_unless($this->brand !== null || $this->user()->isGlobalAdmin(), 403);
    }

    public function shift(int $months): void
    {
        $next = CarbonImmutable::createFromFormat('!Y-m', SalesReport::cleanPeriod($this->period))->addMonths($months);

        if ($next->lte(now()->endOfMonth())) {
            $this->period = $next->format('Y-m') === SalesReport::currentPeriod() ? '' : $next->format('Y-m');
        }
    }

    public function drill(string $brandId = '', string $departmentId = ''): void
    {
        $this->brandFilter = $this->brand === null ? $brandId : '';
        $this->departmentFilter = $departmentId;
    }

    public function render(): View
    {
        $user = $this->user();
        $period = SalesReport::cleanPeriod($this->period);
        $departmentId = ctype_digit($this->departmentFilter) ? (int) $this->departmentFilter : null;

        // The admin area shows one brand at a time, in that brand's own currency.
        $brandChoices = $this->brand === null ? Brand::query()->where('is_active', true)->orderBy('name')->get() : collect();
        $focusBrand = $this->brand
            ?? $brandChoices->firstWhere('id', ctype_digit($this->brandFilter) ? (int) $this->brandFilter : 0)
            ?? $brandChoices->first();

        if ($focusBrand === null) {
            return view('sales::livewire.sales-dashboard-empty')->title('Sales dashboard');
        }

        $report = $this->brand !== null
            ? SalesReport::forBrand($this->brand, $user, $period, $departmentId)
            : SalesReport::forAdmin($period, (int) $focusBrand->getKey(), $departmentId);

        $focusDepartment = $departmentId !== null ? Department::query()->acrossBrands()->where('brand_id', $focusBrand->getKey())->find($departmentId) : null;

        $title = match (true) {
            $report->isPersonal() => 'My target',
            $this->brand !== null => 'Sales',
            default => 'Sales dashboard',
        };

        $totals = $report->totals();

        return view('sales::livewire.sales-dashboard', [
            'report' => $report,
            'title' => $title,
            'month' => $report->start(),
            'isCurrent' => $period === SalesReport::currentPeriod(),
            'totals' => $totals,
            'average' => $totals['count'] > 0 ? $totals['revenue'] / $totals['count'] : null,
            'progress' => $report->progress(),
            'trend' => $report->trend(),
            'brandCards' => $brandChoices->map(fn (Brand $item): array => [
                'brand' => $item,
                'progress' => SalesReport::forAdmin($period, (int) $item->getKey())->progress(),
            ])->all(),
            'departmentRows' => $report->isPersonal() || $focusDepartment !== null ? [] : $report->departmentRows(),
            'agentRows' => $report->agentRows(),
            'topClients' => $report->isPersonal() ? [] : $report->topClients(),
            'sources' => $report->sources(),
            'speed' => $report->isPersonal() ? [] : $report->speed(),
            'recent' => $report->recentSales(),
            'focusBrand' => $focusBrand,
            'focusDepartment' => $focusDepartment,
            'isAdminArea' => $this->brand === null,
            'canSeeClients' => $user->isGlobalAdmin() || $user->isManagerIn($focusBrand),
        ])->title($title);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
