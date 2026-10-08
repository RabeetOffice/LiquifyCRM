<?php

declare(strict_types=1);

namespace App\Modules\Sites\Console;

use App\Modules\Sites\Jobs\RunSiteChecks;
use App\Modules\Sites\Models\Site;
use Illuminate\Console\Command;

final class CheckSitesCommand extends Command
{
    protected $signature = 'sites:check {site? : Id of one website}';

    protected $description = 'Queue the health checks (form, email, database, SSL, uptime) for every active website';

    public function handle(): int
    {
        $ids = Site::query()
            ->acrossBrands()
            ->where('is_active', true)
            ->when($this->argument('site') !== null, fn ($query) => $query->whereKey((int) $this->argument('site')))
            ->pluck('id');

        foreach ($ids as $id) {
            RunSiteChecks::dispatch((int) $id);
        }

        $this->info($ids->count().' website check(s) queued.');

        return self::SUCCESS;
    }
}
