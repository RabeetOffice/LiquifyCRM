<?php

declare(strict_types=1);

namespace App\Modules\Sites\Jobs;

use App\Modules\Sites\Models\Site;
use App\Modules\Sites\Support\HubAgentClient;
use App\Modules\Sites\Support\SiteDatabase;
use App\Modules\Sites\Support\SiteIngestor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

/**
 * Pull mode, every 5 minutes, for websites that must not be touched: read
 * the new rows of the site's own leads table (through the LeadsHub agent or
 * straight from its database) and feed each one into the same pipeline as a
 * pushed lead. Ported from LeadsHub's sync_site_leads().
 *
 * The first run only notes where the table ends, so connecting a website
 * never floods the queue with years of old leads; importing those is the
 * separate "import old leads" tool.
 */
final class PullSiteLeads implements ShouldQueue
{
    use Queueable;

    private const PAGE = 500;

    private const MAX_PAGES = 10;

    public int $tries = 1;

    public int $timeout = 240;

    public function __construct(public readonly int $siteId) {}

    public function handle(SiteIngestor $ingestor, HubAgentClient $agent, SiteDatabase $database): void
    {
        $site = Site::query()->acrossBrands()->with('brand')->find($this->siteId);

        if ($site === null || ! $site->is_active || ! $site->isPull()) {
            return;
        }

        try {
            $driver = $site->pullDriver() ?? throw new RuntimeException('Add the agent or database details of this website first.');

            $note = $site->pull_cursor === null
                ? $this->start($site, $driver, $agent, $database)
                : $this->pull($site, $driver, $ingestor, $agent, $database);
        } catch (Throwable $exception) {
            $note = 'Failed: '.$exception->getMessage();
        }

        $site->forceFill(['last_pulled_at' => now(), 'last_pull_note' => mb_substr($note, 0, 255)])->save();
    }

    private function start(Site $site, string $driver, HubAgentClient $agent, SiteDatabase $database): string
    {
        $cursor = 0;

        if ($driver === 'database') {
            $cursor = $database->maxId($this->databaseConfig($site));
        } else {
            // The agent only pages forward, so walk to the end without storing anything.
            for ($page = 0; $page < 40; $page++) {
                [$rows, $more] = $this->agentRows($site, $agent, $cursor, 1000);

                foreach ($rows as $row) {
                    $cursor = max($cursor, (int) $row['id']);
                }

                if (! $more || $rows === []) {
                    break;
                }
            }
        }

        $site->forceFill(['pull_cursor' => $cursor])->save();

        return 'Connected. Watching for new leads after row '.$cursor.'.';
    }

    private function pull(Site $site, string $driver, SiteIngestor $ingestor, HubAgentClient $agent, SiteDatabase $database): string
    {
        $cursor = (int) $site->pull_cursor;
        $new = 0;

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            if ($driver === 'database') {
                $rows = $database->rows($this->databaseConfig($site), $cursor, self::PAGE);
                $more = count($rows) === self::PAGE;
            } else {
                [$rows, $more] = $this->agentRows($site, $agent, $cursor, self::PAGE);
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
    private function agentRows(Site $site, HubAgentClient $agent, int $cursor, int $limit): array
    {
        $config = $site->agentConfig() ?? throw new RuntimeException('The agent details of this website are missing.');
        $response = $agent->call($config, 'leads', ['since_id' => $cursor, 'limit' => $limit], 60);

        if (! $response['ok']) {
            throw new RuntimeException($response['error']);
        }

        $rows = array_values(array_filter((array) ($response['data']['leads'] ?? []), fn (mixed $row): bool => is_array($row) && is_numeric($row['id'] ?? null)));

        if ($rows === [] && filled($response['data']['note'] ?? null)) {
            throw new RuntimeException((string) $response['data']['note']);
        }

        return [$rows, ! empty($response['data']['has_more'])];
    }
}
