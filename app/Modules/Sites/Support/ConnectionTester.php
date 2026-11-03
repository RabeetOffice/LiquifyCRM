<?php

declare(strict_types=1);

namespace App\Modules\Sites\Support;

use App\Modules\Sites\Actions\UseLiquifyFile;
use App\Modules\Sites\Enums\InboundStatus;
use App\Modules\Sites\Models\Site;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * "Test connection" at the end of the Connect website wizard: is the website
 * reachable, does liquify.php in its root folder answer and find the leads
 * table (or the database, or the webhook), does a signed test lead pass
 * through LiquifyCRM, and has a real lead arrived. A PHP website that still
 * sends each lead itself (the older two-file setup) is read through the one
 * file as soon as the file finds the leads table.
 *
 * @phpstan-type Step array{label: string, ok: ?bool, message: string}
 */
final class ConnectionTester
{
    public function __construct(
        private readonly SiteIngestor $ingestor,
        private readonly UseLiquifyFile $useFile,
        private readonly SiteDatabase $database,
    ) {}

    /** @return list<Step> */
    public function run(Site $site): array
    {
        $steps = [$this->reachable($site)];

        $steps[] = match (true) {
            $site->usesWebhook() => ['label' => 'Webhook address', 'ok' => null, 'message' => 'Paste the address into the Webhook action of the form, then send the form once.'],
            $site->isPull() && $site->pullDriver() !== 'file' => $this->databaseSource($site),
            default => $this->file($site),
        };

        $steps[] = $this->pipeline($site);

        $steps[] = $site->last_lead_at !== null
            ? ['label' => 'First real lead', 'ok' => true, 'message' => 'Last lead received '.$site->last_lead_at->diffForHumans().'.']
            : ['label' => 'First real lead', 'ok' => null, 'message' => $site->isPull() ? 'Submit the form on the website once: the lead is read within a minute and appears in the New queue.' : 'Not yet. Submit the form on the website once; the lead appears in the New queue.'];

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

    /**
     * liquify.php answers, knows the database and its leads table. The first
     * time, LiquifyCRM notes the newest row, so old leads never flood in.
     *
     * @return Step
     */
    private function file(Site $site): array
    {
        // A website that sends each lead itself moves to the one file now.
        $answer = $this->useFile->handle($site);

        return ['label' => 'liquify.php in the root folder', 'ok' => $answer['ok'], 'message' => $answer['message']];
    }

    /** @return Step */
    private function databaseSource(Site $site): array
    {
        $database = $site->databaseConfig();

        if ($database === null) {
            return ['label' => 'Where to read leads', 'ok' => false, 'message' => 'Add the database details in step 2 first.'];
        }

        try {
            return ['label' => 'Leads table', 'ok' => true, 'message' => 'Connected. '.$this->database->count($database).' rows in '.$database['table'].'.'];
        } catch (Throwable $exception) {
            return ['label' => 'Leads table', 'ok' => false, 'message' => 'Could not read the leads table: '.mb_substr($exception->getMessage(), 0, 160)];
        }
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
