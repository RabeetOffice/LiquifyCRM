<?php

declare(strict_types=1);

namespace App\Modules\Sites;

use App\Modules\Core\ModuleServiceProvider;
use App\Modules\Sites\Console\CheckSitesCommand;
use App\Modules\Sites\Console\PullSitesCommand;
use App\Modules\Sites\Models\Site;
use App\Modules\Sites\Policies\SitePolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

final class SitesServiceProvider extends ModuleServiceProvider
{
    protected string $module = 'Sites';

    protected array $policies = [
        Site::class => SitePolicy::class,
    ];

    protected array $commands = [
        CheckSitesCommand::class,
        PullSitesCommand::class,
    ];

    protected function bootModule(): void
    {
        // 120 requests a minute per source, as the security section asks.
        RateLimiter::for('ingest', fn (Request $request): Limit => Limit::perMinute((int) config('liquify.ingest.rate_limit', 120))
            ->by('ingest:'.$request->route('site_key')));
    }
}
