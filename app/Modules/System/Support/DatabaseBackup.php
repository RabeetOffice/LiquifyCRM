<?php

declare(strict_types=1);

namespace App\Modules\System\Support;

use Illuminate\Support\Facades\Artisan;
use Throwable;

/**
 * A database-only backup with spatie/laravel-backup, taken before a system
 * update changes the database. Not final so tests can replace it.
 */
class DatabaseBackup
{
    /** @return array{ok: bool, output: string} */
    public function run(): array
    {
        try {
            $exit = Artisan::call('backup:run', ['--only-db' => true, '--disable-notifications' => true]);

            return ['ok' => $exit === 0, 'output' => trim(Artisan::output())];
        } catch (Throwable $exception) {
            return ['ok' => false, 'output' => $exception->getMessage()];
        }
    }
}
