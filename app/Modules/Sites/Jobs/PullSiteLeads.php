<?php

declare(strict_types=1);

namespace App\Modules\Sites\Jobs;

use App\Modules\Sites\Models\Site;
use App\Modules\Sites\Support\SiteConnector;
use App\Modules\Sites\Support\SiteDatabase;
use App\Modules\Sites\Support\SiteIngestor;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

/**
 * Every minute: read the new rows of a website's own leads table, through
 * liquify.php in its root folder (or straight from its database), and feed
 * each one into the same pipeline as a sent lead. A lead the form also sent
 * at once has the same row id, so it is never taken twice.
 *
 * The first run only notes where the table ends, so connecting a website
 * never floods the queue with years of old leads; importing those is the
 * separate "import old leads" tool.
 */
final class PullSiteLeads implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    private const PAGE = 500;

    private const MAX_PAGES = 10;

    public int $tries = 1;

    public int $timeout = 240;

    /** Asked every minute: a read that is still waiting or running is not queued twice. */
    public int $uniqueFor = 300;

    public function __construct(public readonly int $siteId) {}

    public function uniqueId(): string
    {
        return (string) $this->siteId;
    }

    public function handle(SiteIngestor $ingestor, SiteConnector $connector, SiteDatabase $database): void
    {
        $site = Site::query()->acrossBrands()->with('brand')->find($this->siteId);

        if ($site === null || ! $site->is_active || ! $site->isPull()) {
            return;
        }

        try {
            $driver = $site->pullDriver() ?? throw new RuntimeException('Add the database details of this website first.');

            $note = $site->pull_cursor === null
                ? $this->start($site, $driver, $connector, $database)
                : $this->pull($site, $driver, $ingestor, $connector, $database);
        } catch (Throwable $exception) {
            $note = 'Failed: '.$exception->getMessage();
        }

        $site->forceFill(['last_pulled_at' => now(), 'last_pull_note' => mb_substr($note, 0, 255)])->save();
    }

    private function start(Site $site, string $driver, SiteConnector $connector, SiteDatabase $database): string
    {
        if ($driver === 'database') {
            $cursor = $database->maxId($this->databaseConfig($site));
        } else {
            $ping = $connector->call($site, 'ping', [], 30);

            if (! $ping['ok']) {
                throw new RuntimeException($ping['error']);
            }

            if (($ping['data']['table'] ?? null) === null) {
                throw new RuntimeException((string) ($ping['data']['error'] ?? 'liquify.php found no leads table.'));
            }

            $cursor = (int) ($ping['data']['max_id'] ?? 0);
        }

        $site->forceFill(['pull_cursor' => $cursor])->save();

        return 'Connected. Watching for new leads after row '.$cursor.'.';
    }

    private function pull(Site $site, string $driver, SiteIngestor $ingestor, SiteConnector $connector, SiteDatabase $database): string
    {
        $cursor = (int) $site->pull_cursor;
        $new = 0;

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            if ($driver === 'database') {
                $rows = $database->rows($this->databaseConfig($site), $cursor, self::PAGE);
                $more = count($rows) === self::PAGE;
            } else {
                [$rows, $more] = $this->fileRows($site, $connector, $cursor, self::PAGE);
            }

            foreach ($rows as $row) {
                $id = (int) ($row['id'] ?? 0);

                if ($id <= 0) {
                    continue;
                }

                $event = $ingestor->accept($site, [
                    'form' => (string) ($row['form_type'] ?? $row['form'] ?? 'website'),
                    'external_id' => (string) $id,
                    'submitted_at' => (string) ($row['created_at'] ?? $row['submitted_at'] ?? ''),
                    'fields' => $row,
                    'meta' => array_filter(['page_url' => $row['page_url'] ?? null]),
                    'pulled' => true,
                ]);

                $new += $event->wasRecentlyCreated ? 1 : 0;
                $cursor = max($cursor, $id);
            }

            // Save after every page so a timeout never repeats work.
            $site->forceFill(['pull_cursor' => $cursor])->save();

            if (! $more || $rows === []) {
                break;
            }
        }

        return $new === 0 ? 'No new leads.' : $new.' new '.str('lead')->plural($new).' read.';
    }

    /**
     * @return array{host: string, port: int, database: string, username: string, password: string, table: string}
     */
    private function databaseConfig(Site $site): array
    {
        return $site->databaseConfig() ?? throw new RuntimeException('The database details of this website are missing.');
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: bool} rows and whether more are waiting
     */
    private function fileRows(Site $site, SiteConnector $connector, int $cursor, int $limit): array
    {
        $response = $connector->call($site, 'leads', ['since_id' => $cursor, 'limit' => $limit], 60);

        if (! $response['ok']) {
            throw new RuntimeException($response['error']);
        }

        $rows = array_values(array_filter((array) ($response['data']['leads'] ?? []), fn (mixed $row): bool => is_array($row) && is_numeric($row['id'] ?? null)));

        return [$rows, ! empty($response['data']['has_more'])];
    }
}
