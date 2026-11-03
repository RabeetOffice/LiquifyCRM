<?php

declare(strict_types=1);

namespace App\Modules\Sites\Actions;

use App\Modules\Core\Support\Audit;
use App\Modules\Sites\Enums\SiteMode;
use App\Modules\Sites\Jobs\PullSiteLeads;
use App\Modules\Sites\Models\Site;
use App\Modules\Sites\Support\LiquifySnippet;
use App\Modules\Sites\Support\SiteConnector;

/**
 * Asks liquify.php in a website's root folder whether it can read the leads
 * table; when it can, LiquifyCRM reads that website every minute from then
 * on (user report, 3 Nov 2026: a PHP website set up as "the website sends
 * each lead" lost a lead whose one send failed, and nothing read the table
 * to catch it). The first read only notes the newest row.
 *
 * @phpstan-type Answer array{ok: bool, answered: bool, message: string, ms: int, switched: bool}
 */
final class UseLiquifyFile
{
    public function __construct(private readonly SiteConnector $connector) {}

    /** @return Answer */
    public function handle(Site $site): array
    {
        $ping = $this->connector->call($site, 'ping', [], 20);
        $table = $ping['ok'] ? ($ping['data']['table'] ?? null) : null;

        if (! is_string($table)) {
            $message = $ping['ok'] ? 'The file answers, but: '.($ping['data']['error'] ?? 'it found no leads table.') : $ping['error'];

            // Only liquify.php itself puts its version in an answer.
            return ['ok' => false, 'answered' => $ping['version'] !== '', 'message' => $this->withUpdateHint($message, $ping['version']), 'ms' => $ping['ms'], 'switched' => false];
        }

        $switched = ! $site->isPull();

        if ($switched) {
            $site->forceFill(['mode' => SiteMode::Pull, 'pull_config' => ['driver' => 'file'], 'pull_cursor' => null])->save();

            Audit::log('sites.reads_through_file', $site, ['table' => $table], 'LiquifyCRM now reads '.$site->domain.' every minute through liquify.php', brand: $site->brand_id, logName: 'sites');
        }

        if ($site->pull_cursor === null) {
            PullSiteLeads::dispatchSync((int) $site->getKey());
            $site->refresh();
        }

        $rows = (int) ($ping['data']['rows'] ?? 0);

        return [
            'ok' => true,
            'answered' => true,
            'message' => 'Answered in '.$ping['ms'].' ms. It reads the '.$table.' table ('.number_format($rows).' '.str('row')->plural($rows).'); new leads are read every minute.',
            'ms' => $ping['ms'],
            'switched' => $switched,
        ];
    }

    private function withUpdateHint(string $message, string $version): string
    {
        return LiquifySnippet::isOutdated($version)
            ? $message.' The website has liquify.php '.$version.': download it again and upload it over the old one, version '.LiquifySnippet::VERSION.' finds more kinds of settings.'
            : $message;
    }
}
