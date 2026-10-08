<?php

declare(strict_types=1);

namespace App\Modules\Activity\Console;

use App\Modules\Activity\Models\LogExport;
use App\Modules\Activity\Support\LogArchiver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Nightly: archive yesterday's audit trail to a compressed file, delete rows
 * past the database retention, delete archive files past the archive
 * retention, and remove downloads whose 24 hours are over.
 */
final class ArchiveLogsCommand extends Command
{
    protected $signature = 'logs:archive';

    protected $description = 'Archive the audit trail to daily files and apply the retention settings';

    public function handle(LogArchiver $archiver): int
    {
        $result = $archiver->nightly();

        $expired = LogExport::query()->where('expires_at', '<', now())->whereNotNull('path')->get();

        foreach ($expired as $export) {
            Storage::disk(LogArchiver::DISK)->delete((string) $export->path);
            $export->update(['path' => null, 'status' => 'expired']);
        }

        $this->info($result['archived'].' day(s) archived, '.$result['deleted'].' row(s) deleted, '.$result['files'].' old archive file(s) and '.$expired->count().' expired download(s) removed.');

        return self::SUCCESS;
    }
}
