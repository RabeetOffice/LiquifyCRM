<?php

declare(strict_types=1);

namespace App\Modules\Activity\Support;

use App\Modules\Activity\Models\Activity;
use App\Modules\Activity\Models\LogArchive;
use App\Modules\Activity\Settings\LogSettings;
use App\Modules\Leads\Models\Lead;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Keeps the audit trail small without losing anything: every finished day is
 * written to logs/YYYY/MM/YYYY-MM-DD.jsonl.gz in private storage (with a row
 * count and checksum), and only days that are safely archived are ever
 * deleted from the database, in batches of 1,000 so the app stays fast.
 */
final class LogArchiver
{
    public const DISK = 'local';

    public const BATCH = 1000;

    public function __construct(private readonly LogSettings $settings) {}

    /**
     * Write one day to its archive file. A day that is already archived with
     * the same number of rows is left alone.
     */
    public function archiveDay(CarbonInterface $day): ?LogArchive
    {
        $day = CarbonImmutable::parse($day->toDateString());
        $query = fn () => Activity::query()->where('created_at', '>=', $day)->where('created_at', '<', $day->addDay());
        $rows = $query()->count();
        $existing = LogArchive::query()->whereDate('date', $day)->first();

        if ($rows === 0) {
            return $existing;
        }

        if ($existing !== null && $existing->rows >= $rows && Storage::disk(self::DISK)->exists($existing->path)) {
            return $existing;
        }

        $path = 'logs/'.$day->format('Y/m').'/'.$day->toDateString().'.jsonl.gz';
        $disk = Storage::disk(self::DISK);
        $disk->makeDirectory(dirname($path));

        $handle = gzopen($disk->path($path), 'wb9');

        if ($handle === false) {
            throw new RuntimeException('The archive file '.$path.' could not be written.');
        }

        $written = 0;

        $query()->orderBy('id')->chunkById(self::BATCH, function ($entries) use ($handle, &$written): void {
            foreach ($entries as $entry) {
                gzwrite($handle, json_encode($entry->getAttributes(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
                $written++;
            }
        });

        gzclose($handle);

        return LogArchive::query()->updateOrCreate(['date' => $day->toDateString()], [
            'rows' => $written,
            'path' => $path,
            'size_bytes' => (int) $disk->size($path),
            'checksum' => (string) hash_file('sha256', $disk->path($path)),
        ]);
    }

    /**
     * Archive every finished day that still has rows in the database and is not archived yet.
     *
     * @return int days archived
     */
    public function archivePending(?CarbonInterface $until = null): int
    {
        $until = CarbonImmutable::parse(($until ?? now()->subDay())->toDateString());
        $first = Activity::query()->min('created_at');

        if ($first === null) {
            return 0;
        }

        $archived = 0;

        for ($day = CarbonImmutable::parse($first)->startOfDay(); $day->lte($until); $day = $day->addDay()) {
            $before = LogArchive::query()->whereDate('date', $day)->value('rows');
            $archive = $this->archiveDay($day);

            if ($archive !== null && $archive->rows !== $before) {
                $archived++;
            }
        }

        return $archived;
    }

    /**
     * Delete rows older than a date from the database. Every day is archived
     * first, and a day whose archive is missing is never deleted.
     *
     * @return int rows deleted
     */
    public function deleteBefore(CarbonInterface $before): int
    {
        $before = CarbonImmutable::parse($before->toDateString());
        $this->archivePending($before->subDay());

        $first = Activity::query()->where('created_at', '<', $before)->min('created_at');
        $deleted = 0;

        if ($first === null) {
            return 0;
        }

        for ($day = CarbonImmutable::parse($first)->startOfDay(); $day->lt($before); $day = $day->addDay()) {
            $archive = LogArchive::query()->whereDate('date', $day)->first();
            $rows = Activity::query()->where('created_at', '>=', $day)->where('created_at', '<', $day->addDay())->count();

            if ($this->deletable($day)->count() === 0) {
                continue;
            }

            if ($archive === null || $archive->rows < $rows || ! Storage::disk(self::DISK)->exists($archive->path)) {
                throw new RuntimeException('The logs of '.$day->toDateString().' are not safely archived, so nothing from that day on was deleted.');
            }

            do {
                $batch = $this->deletable($day)->limit(self::BATCH)->delete();
                $deleted += $batch;
            } while ($batch > 0);
        }

        return $deleted;
    }

    /**
     * What may leave the database for a day: everything except the entries on
     * a lead's own timeline. Those are the lead's history (who assigned it,
     * every status change, each sale) and stay for as long as the lead does;
     * they are still copied to the archive like every other entry.
     *
     * @return Builder<Activity>
     */
    private function deletable(CarbonImmutable $day): Builder
    {
        return Activity::query()
            ->where('created_at', '>=', $day)
            ->where('created_at', '<', $day->addDay())
            ->where(fn (Builder $query): Builder => $query->whereNull('subject_type')->orWhere('subject_type', '!=', (new Lead)->getMorphClass()));
    }

    /**
     * The nightly clean-up from the retention settings: old rows leave the
     * database, old archive files leave the disk.
     *
     * @return array{archived: int, deleted: int, files: int}
     */
    public function nightly(): array
    {
        $archived = $this->archivePending();
        $deleted = $this->deleteBefore(now()->subDays($this->settings->database_days));
        $files = 0;

        foreach (LogArchive::query()->whereDate('date', '<', now()->subDays($this->settings->archive_days))->get() as $archive) {
            Storage::disk(self::DISK)->delete($archive->path);
            $archive->delete();
            $files++;
        }

        return ['archived' => $archived, 'deleted' => $deleted, 'files' => $files];
    }

    /** @return array{rows: int, archives: int, archive_bytes: int, oldest: ?string} for the storage card */
    public function storage(): array
    {
        return [
            'rows' => Activity::query()->count(),
            'archives' => LogArchive::query()->count(),
            'archive_bytes' => (int) LogArchive::query()->sum('size_bytes'),
            'oldest' => Activity::query()->min('created_at'),
        ];
    }
}
