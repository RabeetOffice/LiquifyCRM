<?php

declare(strict_types=1);

namespace App\Modules\Sites\Jobs;

use App\Modules\Auth\Models\User;
use App\Modules\Core\Notifications\AppNotification;
use App\Modules\Core\Support\Audit;
use App\Modules\Core\Support\CurrentBrand;
use App\Modules\Leads\Actions\CreateLead;
use App\Modules\Leads\Enums\LeadSource;
use App\Modules\Leads\Models\Lead;
use App\Modules\Sites\Models\Site;
use App\Modules\Sites\Support\HubAgentClient;
use App\Modules\Sites\Support\SiteDatabase;
use App\Modules\Sites\Support\SiteLeadNormalizer;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

/**
 * Import old leads: read a website's existing leads table once for a chosen
 * date range. Original dates are kept, duplicates are merged into the lead
 * that already exists, each lead is tagged Imported, and nobody is notified
 * per lead. The person who started it gets one summary.
 */
final class ImportSiteHistory implements ShouldQueue
{
    use Queueable;

    private const PAGE = 500;

    private const MAX_PAGES = 200;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(
        public readonly int $siteId,
        public readonly string $from,
        public readonly string $until,
        public readonly ?int $userId = null,
    ) {}

    public function handle(HubAgentClient $agent, SiteDatabase $database, SiteLeadNormalizer $normalizer, CreateLead $createLead): void
    {
        $site = Site::query()->acrossBrands()->with('brand')->find($this->siteId);
        $user = $this->userId !== null ? User::query()->find($this->userId) : null;

        if ($site === null) {
            return;
        }

        $from = CarbonImmutable::parse($this->from)->startOfDay();
        $until = CarbonImmutable::parse($this->until)->endOfDay();
        $counts = ['created' => 0, 'merged' => 0, 'skipped' => 0];

        try {
            app(CurrentBrand::class)->within($site->brand, function () use ($site, $agent, $database, $normalizer, $createLead, $user, $from, $until, &$counts): void {
                $cursor = 0;

                for ($page = 0; $page < self::MAX_PAGES; $page++) {
                    [$rows, $more] = $this->rows($site, $agent, $database, $cursor);

                    foreach ($rows as $row) {
                        $id = (int) ($row['id'] ?? 0);
                        $cursor = max($cursor, $id);
                        $data = $normalizer->normalise(['fields' => $row, 'form' => $row['form_type'] ?? '', 'submitted_at' => $row['created_at'] ?? $row['submitted_at'] ?? null]);
                        $date = $data['created_at'];

                        $outside = $date === null || $date->lt($from) || $date->gt($until);
                        $empty = $data['name'] === null && $data['email'] === null && $data['phone'] === null;
                        $externalId = $site->getKey().':'.$id;

                        if ($id <= 0 || $outside || $empty || $data['spam'] || Lead::query()->withTrashed()->where('source_type', LeadSource::Website->value)->where('external_id', $externalId)->exists()) {
                            $counts['skipped']++;

                            continue;
                        }

                        $result = $createLead->handle($site->brand, array_filter([
                            'source_type' => LeadSource::Website,
                            'source_id' => $site->getKey(),
                            'external_id' => $externalId,
                            'name' => $data['name'],
                            'email' => $data['email'],
                            'phone' => $data['phone'],
                            'message' => $data['message'],
                            'fields' => ['website' => $site->name, ...$data['fields']],
                            'utm' => $data['utm'] !== [] ? $data['utm'] : null,
                            'created_at' => $date,
                            'imported_at' => now(),
                            'silent' => true,
                        ], fn (mixed $value): bool => $value !== null), $user);

                        $counts[$result->wasMerged ? 'merged' : 'created']++;
                    }

                    if (! $more || $rows === []) {
                        break;
                    }
                }
            });

            $summary = $counts['created'].' imported, '.$counts['merged'].' joined to existing leads, '.$counts['skipped'].' skipped (outside the dates, already here, or nobody to contact).';
            $ok = true;
        } catch (Throwable $exception) {
            $summary = 'Stopped after '.$counts['created'].' leads: '.$exception->getMessage();
            $ok = false;
        }

        Audit::log('leads.history_imported', $site, ['from' => $this->from, 'until' => $this->until, ...$counts], 'Old leads imported from '.$site->domain.': '.$counts['created'].' new', brand: $site->brand_id, causer: $user, logName: 'leads');

        $user?->notify(new AppNotification(
            $ok ? 'Old leads imported from '.$site->name : 'Import from '.$site->name.' stopped',
            $summary,
            $ok ? 'success' : 'danger',
            $site->brand->url('leads/distribute'),
            icon: 'heroicon-o-arrow-down-on-square-stack',
            actionLabel: 'Distribute them',
        ));
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: bool}
     */
    private function rows(Site $site, HubAgentClient $agent, SiteDatabase $database, int $cursor): array
    {
        if ($site->pullDriver() === 'database' && ($config = $site->databaseConfig()) !== null) {
            $rows = $database->rows($config, $cursor, self::PAGE);

            return [$rows, count($rows) === self::PAGE];
        }

        $config = $site->agentConfig() ?? throw new RuntimeException('Add the agent or database details of this website under Settings first.');
        $response = $agent->call($config, 'leads', ['since_id' => $cursor, 'limit' => self::PAGE], 60);

        if (! $response['ok']) {
            throw new RuntimeException($response['error']);
        }

        $rows = array_values(array_filter((array) ($response['data']['leads'] ?? []), fn (mixed $row): bool => is_array($row) && is_numeric($row['id'] ?? null)));

        return [$rows, ! empty($response['data']['has_more'])];
    }
}
