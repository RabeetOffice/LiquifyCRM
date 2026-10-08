<?php

declare(strict_types=1);

namespace App\Modules\Sites\Jobs;

use App\Modules\Sites\Models\Site;
use App\Modules\Sites\Support\SiteHealthChecker;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Health checks for one website, from the scheduler or the "Check now" button. */
final class RunSiteChecks implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public readonly int $siteId) {}

    public function handle(SiteHealthChecker $checker): void
    {
        $site = Site::query()->acrossBrands()->with('brand')->find($this->siteId);

        if ($site !== null) {
            $checker->run($site);
        }
    }
}
