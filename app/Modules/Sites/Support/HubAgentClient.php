<?php

declare(strict_types=1);

namespace App\Modules\Sites\Support;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Talks to the LeadsHub agent (hub-agent.php) on a website, ported from
 * LeadsHub's agent-client.php. Every request is signed:
 *
 *   X-Hub-Key, X-Hub-Timestamp, X-Hub-Nonce,
 *   X-Hub-Signature: hex HMAC-SHA256(secret, ts."\n".nonce."\n".body)
 *   body: {"action":"...","params":{...}}
 *
 * The agent is read-only: it never writes to the website.
 */
final class HubAgentClient
{
    /**
     * @param  array{url: string, key: string, secret: string}  $agent
     * @param  array<string, mixed>  $params
     * @return array{ok: bool, data: array<string, mixed>, error: string, ms: int}
     */
    public function call(array $agent, string $action, array $params = [], int $timeout = 20): array
    {
        $started = microtime(true);
        $body = (string) json_encode(['action' => $action, 'params' => (object) $params, 'protocol' => 1, 'hub' => config('app.url')], JSON_UNESCAPED_SLASHES);
        $timestamp = (string) time();
        $nonce = Str::lower(Str::random(32));

        try {
            $response = Http::timeout($timeout)
                ->connectTimeout(min(10, $timeout))
                ->withUserAgent('LiquifyCRM')
                ->withHeaders([
                    'Accept' => 'application/json',
                    'X-Hub-Key' => $agent['key'],
                    'X-Hub-Timestamp' => $timestamp,
                    'X-Hub-Nonce' => $nonce,
                    'X-Hub-Signature' => hash_hmac('sha256', $timestamp."\n".$nonce."\n".$body, $agent['secret']),
                ])
                ->withBody($body, 'application/json')
                ->post($agent['url']);
        } catch (Throwable $exception) {
            return ['ok' => false, 'data' => [], 'error' => 'The website could not be reached: '.$exception->getMessage(), 'ms' => $this->ms($started)];
        }

        $json = $response->json();

        if (! is_array($json)) {
            $error = $response->status() === 404
                ? 'hub-agent.php was not found at '.$agent['url'].'. Upload it to the website, or correct the address.'
                : 'The website did not answer in the expected format (HTTP '.$response->status().').';

            return ['ok' => false, 'data' => [], 'error' => $error, 'ms' => $this->ms($started)];
        }

        if (empty($json['ok'])) {
            return ['ok' => false, 'data' => [], 'error' => (string) ($json['error'] ?? 'The agent returned an error.'), 'ms' => $this->ms($started)];
        }

        return ['ok' => true, 'data' => is_array($json['data'] ?? null) ? $json['data'] : [], 'error' => '', 'ms' => $this->ms($started)];
    }

    private function ms(float $started): int
    {
        return (int) round((microtime(true) - $started) * 1000);
    }
}
