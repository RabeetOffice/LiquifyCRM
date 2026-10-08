<?php

declare(strict_types=1);

namespace App\Modules\Sites\Support;

use App\Modules\Sites\Enums\InboundStatus;
use App\Modules\Sites\Models\Site;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * "Test connection" at the end of the Connect website wizard: is the website
 * reachable, is liquify.php (or the agent, or the leads table) in place, does
 * a signed test lead pass through LiquifyCRM, and has a real lead arrived.
 *
 * @phpstan-type Step array{label: string, ok: ?bool, message: string}
 */
final class ConnectionTester
{
    public function __construct(
        private readonly SiteIngestor $ingestor,
        private readonly HubAgentClient $agent,
        private readonly SiteDatabase $database,
    ) {}

    /** @return list<Step> */
    public function run(Site $site): array
    {
        $steps = [$this->reachable($site)];

        $steps[] = match (true) {
            $site->isPull() => $this->pullSource($site),
            $site->usesWebhook() => ['label' => 'Webhook address', 'ok' => null, 'message' => 'Paste the address into the form\'s Webhook action, then submit the form once.'],
            default => $this->snippetUploaded($site),
        };

        $steps[] = $this->pipeline($site);

        $steps[] = $site->last_lead_at !== null
            ? ['label' => 'First real lead', 'ok' => true, 'message' => 'Last lead received '.$site->last_lead_at->diffForHumans().'.']
            : ['label' => 'First real lead', 'ok' => null, 'message' => $site->isPull() ? 'New leads are read every 5 minutes.' : 'Not yet. Submit the form on the website once; the lead appears in the New queue.'];

        return $steps;
    }

    /** @return Step */
    private function reachable(Site $site): array
    {
        try {
            $code = Http::timeout(15)->connectTimeout(8)->withUserAgent('LiquifyCRM')->get($site->url.'/')->status();
        } catch (Throwable $exception) {
            return ['label' => 'Website answers', 'ok' => false, 'message' => 'Could not reach '.$site->domain.'. Check the address. ('.mb_substr($exception->getMessage(), 0, 120).')'];
        }

        return $code < 400
            ? ['label' => 'Website answers', 'ok' => true, 'message' => $site->domain.' is up.']
            : ['label' => 'Website answers', 'ok' => false, 'message' => $site->domain." answered with error {$code}."];
    }

    /** @return Step */
    private function snippetUploaded(Site $site): array
    {
        try {
            $code = Http::timeout(15)->connectTimeout(8)->withUserAgent('LiquifyCRM')->get($site->pageUrl('/includes/liquify.php'))->status();
        } catch (Throwable) {
            return ['label' => 'liquify.php uploaded', 'ok' => null, 'message' => 'Could not check the file from here.'];
        }

        return match (true) {
            $code === 404 => ['label' => 'liquify.php uploaded', 'ok' => false, 'message' => 'includes/liquify.php was not found on the website yet. Upload it and test again.'],
            // Many sites block the includes folder from browsers; that still means the file can be there.
            in_array($code, [401, 403], true) => ['label' => 'liquify.php uploaded', 'ok' => null, 'message' => 'The includes folder is protected from browsers (good). Submit the form once to confirm.'],
            default => ['label' => 'liquify.php uploaded', 'ok' => true, 'message' => 'includes/liquify.php is on the website.'],
        };
    }

    /** @return Step */
    private function pullSource(Site $site): array
    {
        $driver = $site->pullDriver();

        try {
            if ($driver === 'agent' && ($agent = $site->agentConfig()) !== null) {
                $ping = $this->agent->call($agent, 'ping', [], 15);

                return $ping['ok']
                    ? ['label' => 'Agent on the website', 'ok' => true, 'message' => 'The agent answered in '.$ping['ms'].' ms.']
                    : ['label' => 'Agent on the website', 'ok' => false, 'message' => $ping['error']];
            }

            if ($driver === 'database' && ($database = $site->databaseConfig()) !== null) {
                return ['label' => 'Leads table', 'ok' => true, 'message' => 'Connected. '.$this->database->count($database).' rows in '.$database['table'].'.'];
            }
        } catch (Throwable $exception) {
            return ['label' => 'Leads table', 'ok' => false, 'message' => 'Could not read the leads table: '.mb_substr($exception->getMessage(), 0, 160)];
        }

        return ['label' => 'Where to read leads', 'ok' => false, 'message' => 'Add the agent or database details in step 2 first.'];
    }

    /**
     * A signed test lead through the same pipeline; it never becomes a real lead.
     *
     * @return Step
     */
    private function pipeline(Site $site): array
    {
        $payload = [
            'test' => true,
            'form' => 'connection-test',
            'external_id' => 'test-'.Str::uuid()->toString(),
            'submitted_at' => now()->toIso8601String(),
            'fields' => [],
            'meta' => [],
        ];

        $body = (string) json_encode($payload);
        $timestamp = (string) time();
        $signature = IngestSignature::sign($site->secret, $timestamp, $body);

        if (! IngestSignature::isFresh($timestamp) || ! IngestSignature::matches($site->secret, $timestamp, $body, $signature)) {
            return ['label' => 'LiquifyCRM accepts leads', 'ok' => false, 'message' => 'The signature check failed. Create a new secret and download liquify.php again.'];
        }

        $event = $this->ingestor->accept($site, $payload, sync: true)->refresh();

        return $event->status === InboundStatus::Done
            ? ['label' => 'LiquifyCRM accepts leads', 'ok' => true, 'message' => 'A signed test passed. Leads from this website go to '.$site->brand->name.'.']
            : ['label' => 'LiquifyCRM accepts leads', 'ok' => false, 'message' => $event->error ?? 'The test could not be processed.'];
    }
}
