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
 * The sales dashboard. In the admin area it covers every brand in the
 * reporting currency and drills down: brand, then department, then its
 * Agents. Inside a brand, a Manager sees their departments and an Agent
 * their own target bar and sales.
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
        $brandId = $this->brand === null && ctype_digit($this->brandFilter) ? (int) $this->brandFilter : null;

        $report = $this->brand !== null
            ? SalesReport::forBrand($this->brand, $user, $period, $departmentId)
            : SalesReport::forAdmin($period, $brandId, $departmentId);

        $focusBrand = $this->brand ?? ($brandId !== null ? Brand::query()->find($brandId) : null);
        $focusDepartment = $departmentId !== null ? Department::query()->acrossBrands()->find($departmentId) : null;

        $title = match (true) {
            $report->isPersonal() => 'My target',
            $this->brand !== null => 'Sales',
            default => 'Sales dashboard',
        };

        return view('sales::livewire.sales-dashboard', [
            'report' => $report,
            'title' => $title,
            'month' => $report->start(),
            'isCurrent' => $period === SalesReport::currentPeriod(),
            'totals' => $report->totals(),
            'progress' => $report->progress(),
            'brandRows' => $this->brand === null && $brandId === null ? $report->brandRows() : [],
            'departmentRows' => $report->isPersonal() || $focusDepartment !== null ? [] : $report->departmentRows(),
            'agentRows' => $report->agentRows(),
            'topClients' => $report->isPersonal() ? [] : $report->topClients(),
            'sources' => $report->sources(),
            'speed' => $report->isPersonal() ? [] : $report->speed(),
            'missingRates' => $report->missingRates(),
            'recent' => $report->recentSales(),
            'focusBrand' => $focusBrand,
            'focusDepartment' => $focusDepartment,
            'isAdminArea' => $this->brand === null,
            'canSeeClients' => $user->isGlobalAdmin() || ($focusBrand !== null && $user->isManagerIn($focusBrand)),
        ])->title($title);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
