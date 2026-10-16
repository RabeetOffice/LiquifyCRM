<?php

declare(strict_types=1);

namespace App\Modules\Leads\Console;

use App\Modules\Leads\Actions\DeleteLeadForever;
use App\Modules\Leads\Models\Lead;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Nightly: a lead that has been in the bin for DeleteLeadForever::KEEP_DAYS
 * days is deleted for good, in every brand.
 */
final class EmptyBinCommand extends Command
{
    protected $signature = 'leads:empty-bin';

    protected $description = 'Delete for good the leads that have been in the bin for '.DeleteLeadForever::KEEP_DAYS.' days';

    public function handle(DeleteLeadForever $delete): int
    {
        $count = 0;

        Lead::query()
            ->acrossBrands()
            ->inBin()
            ->where('deleted_at', '<', now()->subDays(DeleteLeadForever::KEEP_DAYS))
            ->chunkById(100, function (Collection $leads) use ($delete, &$count): void {
                foreach ($leads as $lead) {
                    $delete->handle($lead);
                    $count++;
                }
            });

        $this->info($count.' '.str('lead')->plural($count).' deleted for good from the bin.');

        return self::SUCCESS;
    }
}
