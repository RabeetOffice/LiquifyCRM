<?php

declare(strict_types=1);

namespace App\Modules\System\Console;

use App\Modules\Core\Support\SmallPhoto;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Nightly tidying of two tables nothing else empties. The cache table keeps an entry that ran out until somebody asks
 * for the same key again, and the limits of a website visitor are never asked for again. The bell keeps every entry:
 * one that was read goes after 90 days; an entry nobody has read stays. Profile photos kept at their uploaded size
 * (megabytes, shown at a few dozen pixels) are made small.
 */
final class TidyDatabaseCommand extends Command
{
    /** Days a bell entry stays after it was read. */
    public const READ_NOTIFICATION_DAYS = 90;

    protected $signature = 'liquify:tidy';

    protected $description = 'Remove run-out cache entries and bell entries read long ago';

    public function handle(): int
    {
        $cache = 0;

        if (config('cache.default') === 'database') {
            $connection = DB::connection(config('cache.stores.database.connection'));

            foreach ([config('cache.stores.database.table', 'cache'), config('cache.stores.database.lock_table', 'cache_locks')] as $table) {
                if (is_string($table) && Schema::connection($connection->getName())->hasTable($table)) {
                    $cache += $connection->table($table)->where('expiration', '<', now()->getTimestamp())->delete();
                }
            }
        }

        $bell = Schema::hasTable('notifications')
            ? DB::table('notifications')->whereNotNull('read_at')->where('read_at', '<', now()->subDays(self::READ_NOTIFICATION_DAYS))->delete()
            : 0;

        $photos = SmallPhoto::shrinkStored();

        $this->info("Removed {$cache} run-out cache entries and {$bell} old bell entries; made {$photos} heavy profile photos small.");

        return self::SUCCESS;
    }
}
