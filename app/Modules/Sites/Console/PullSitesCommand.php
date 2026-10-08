<?php

declare(strict_types=1);

namespace App\Modules\Sites\Console;

use App\Modules\Sites\Enums\SiteMode;
use App\Modules\Sites\Jobs\PullSiteLeads;
use App\Modules\Sites\Models\Site;
use Illuminate\Console\Command;

final class PullSitesCommand extends Command
{
    protected $signature = 'sites:pull';

    protected $description = 'Queue a read of new leads for every website in pull mode';

    public function handle(): int
    {
        $ids = Site::query()
            ->acrossBrands()
            ->where('is_active', true)
            ->where('mode', SiteMode::Pull->value)
            ->pluck('id');

        foreach ($ids as $id) {
            PullSiteLeads::dispatch((int) $id);
        }

        $this->info($ids->count().' website(s) queued.');

        return self::SUCCESS;
    }
}
