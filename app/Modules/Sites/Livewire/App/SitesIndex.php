<?php

declare(strict_types=1);

namespace App\Modules\Sites\Livewire\App;

use App\Modules\Brands\Models\Brand;
use App\Modules\Core\Livewire\Concerns\WithToasts;
use App\Modules\Core\Support\Audit;
use App\Modules\Sites\Enums\CheckType;
use App\Modules\Sites\Models\Site;
use App\Modules\Sites\Models\SiteCheck;
use App\Modules\Sites\Support\SiteHealthChecker;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Websites of the current brand: connection badge with the last lead time
 * and the five health checks in red / amber / green.
 */
#[Layout('components.layouts.app')]
#[Title('Websites')]
final class SitesIndex extends Component
{
    use AuthorizesRequests;
    use WithToasts;

    public Brand $brand;

    public function mount(Brand $brand): void
    {
        $this->brand = $brand;
        $this->authorize('viewAny', Site::class);
    }

    public function checkNow(int $siteId, SiteHealthChecker $checker): void
    {
        $site = Site::query()->with('brand')->findOrFail($siteId);
        $this->authorize('update', $site);

        set_time_limit(120);
        $status = $checker->run($site);

        Audit::log('sites.checked', $site, ['status' => $status->value], 'Health checks run for '.$site->name, logName: 'sites');

        $this->toast($site->name.': '.$status->getLabel(), 'Form, email, database, SSL and uptime were checked just now.', $status->getColor() === 'gray' ? 'info' : $status->getColor());
    }

    public function render(): View
    {
        $sites = Site::query()->orderBy('name')->get();

        return view('sites::livewire.app.sites-index', [
            'sites' => $sites,
            'checks' => SiteCheck::latestFor($sites->modelKeys()),
            'types' => CheckType::cases(),
            'canManage' => (bool) auth()->user()?->can('create', Site::class),
        ]);
    }
}
