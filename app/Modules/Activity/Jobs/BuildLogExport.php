<?php

declare(strict_types=1);

namespace App\Modules\Activity\Jobs;

use App\Modules\Activity\Models\Activity;
use App\Modules\Activity\Models\LogExport;
use App\Modules\Activity\Support\LogArchiver;
use App\Modules\Core\Notifications\AppNotification;
use App\Modules\Core\Support\SpreadsheetText;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Throwable;
use ZipArchive;

/**
 * Builds "Download last 7 / 15 / 30 days": a CSV inside a ZIP in private
 * storage. The person gets a notification with a link that works for 24 hours.
 */
final class BuildLogExport implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public readonly int $exportId) {}

    public function handle(): void
    {
        $export = LogExport::query()->with('user')->find($this->exportId);

        if ($export === null || $export->status !== 'pending') {
            return;
        }

        // Logs are for Admins only: a request left behind by somebody who is no longer one is not built.
        if (! $export->user?->isGlobalAdmin()) {
            $export->update(['status' => 'failed', 'error' => 'Only Admins can download logs.']);

            return;
        }

        $disk = Storage::disk(LogArchiver::DISK);
        $name = 'liquifycrm-logs-last-'.$export->range_days.'-days-'.now()->format('Y-m-d-His');
        $csvPath = 'log-exports/'.$name.'-'.$export->getKey().'.csv';
        $zipPath = 'log-exports/'.$name.'-'.$export->getKey().'.zip';

        try {
            $disk->makeDirectory('log-exports');
            $csv = fopen($disk->path($csvPath), 'wb');

            if ($csv === false) {
                throw new \RuntimeException('The export file could not be written.');
            }

            fputcsv($csv, ['Time', 'Area', 'Event', 'What happened', 'Who', 'Brand', 'Department', 'Subject', 'IP address', 'Browser', 'Details']);
            $rows = 0;

            Activity::query()
                ->with(['causer', 'brand', 'department'])
                ->where('created_at', '>=', now()->subDays($export->range_days)->startOfDay())
                ->when($export->brand_id !== null, fn ($query) => $query->where('brand_id', $export->brand_id))
                ->when($export->department_ids !== null, fn ($query) => $query->whereIn('department_id', $export->department_ids ?? []))
                ->orderBy('id')
                ->chunkById(1000, function ($entries) use ($csv, &$rows): void {
                    foreach ($entries as $entry) {
                        fputcsv($csv, [
                            $entry->created_at?->toDateTimeString(),
                            $entry->log_name,
                            $entry->event,
                            // Descriptions carry lead names and messages: text, never a formula.
                            SpreadsheetText::safe($entry->description),
                            SpreadsheetText::safe($entry->causer?->getAttribute('name') ?? 'System'),
                            $entry->brand?->name,
                            $entry->department?->name,
                            $entry->subject_type ? class_basename((string) $entry->subject_type).' #'.$entry->subject_id : '',
                            $entry->ip,
                            SpreadsheetText::safe($entry->user_agent),
                            json_encode($entry->properties, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        ]);
                        $rows++;
                    }
                });

            fclose($csv);

            $zip = new ZipArchive;

            if ($zip->open($disk->path($zipPath), ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('The ZIP file could not be created.');
            }

            $zip->addFile($disk->path($csvPath), $name.'.csv');
            $zip->close();
            $disk->delete($csvPath);

            $export->update(['status' => 'ready', 'path' => $zipPath, 'rows' => $rows, 'expires_at' => now()->addDay()]);

            $export->user->notify(new AppNotification(
                'Your log download is ready',
                'Last '.$export->range_days.' days, '.number_format($rows).' '.str('entry')->plural($rows).'. The link works for 24 hours.',
                'success',
                route('logs.exports.download', ['export' => $export->getKey()]),
                mail: true,
                icon: 'heroicon-o-arrow-down-tray',
                actionLabel: 'Download',
            ));
        } catch (Throwable $exception) {
            $disk->delete([$csvPath, $zipPath]);
            $export->update(['status' => 'failed', 'error' => mb_substr($exception->getMessage(), 0, 500)]);
            report($exception);
        }
    }
}
