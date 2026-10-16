<?php

declare(strict_types=1);

namespace App\Modules\Sites\Support;

use App\Modules\Sites\Models\Site;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Asks liquify.php in the root of a website (the one file a PHP website needs)
 * a question: "ping", "leads" (rows after an id) or "diagnostics" (its email
 * and database). The question is signed like a lead the other way round:
 * X-Liquify-Timestamp and X-Liquify-Signature, HMAC-SHA256 of
 * "timestamp.body" with the website's secret. The file only reads.
 */
final class SiteConnector
{
    /**
     * @param  array<string, mixed>  $params
     * @return array{ok: bool, data: array<string, mixed>, error: string, ms: int}
     */
    public function call(Site $site, string $action, array $params = [], int $timeout = 20): array
    {
        $started = microtime(true);
        $url = $site->connectorUrl();
        $body = (string) json_encode(['action' => $action, 'params' => (object) $params], JSON_UNESCAPED_SLASHES);
        $timestamp = (string) time();

        try {
            $response = Http::timeout($timeout)
                ->connectTimeout(min(10, $timeout))
                ->withUserAgent('LiquifyCRM')
                ->withHeaders([
                    'Accept' => 'application/json',
                    'X-Liquify-Timestamp' => $timestamp,
                    'X-Liquify-Signature' => IngestSignature::sign($site->secret, $timestamp, $body),
                ])
                ->withBody($body, 'application/json')
                ->post($url);
        } catch (Throwable $exception) {
            return $this->failed('The website could not be reached: '.$exception->getMessage(), $started);
        }

        $json = $response->json();

        if (! is_array($json) || ! array_key_exists('liquify', $json)) {
            return $this->failed(match (true) {
                $response->status() === 404 => 'liquify.php was not found in the root folder of '.$site->domain.'. Upload it next to index.php and test again.',
                $response->status() === 403 => 'The website refused the question (error 403). Its hosting firewall may block it; liquify.php must be reachable at '.$url.'.',
                default => 'The website did not answer as liquify.php does (error '.$response->status().'). Upload the file from LiquifyCRM to the root folder.',
            }, $started);
        }

        if (empty($json['ok'])) {
            return $this->failed((string) ($json['error'] ?? 'liquify.php could not answer.'), $started);
        }

        return ['ok' => true, 'data' => is_array($json['data'] ?? null) ? $json['data'] : [], 'error' => '', 'ms' => $this->ms($started)];
    }

    /** @return array{ok: false, data: array<string, mixed>, error: string, ms: int} */
    private function failed(string $error, float $started): array
    {
        return ['ok' => false, 'data' => [], 'error' => $error, 'ms' => $this->ms($started)];
    }

    private function ms(float $started): int
    {
        return (int) round((microtime(true) - $started) * 1000);
    }
}
