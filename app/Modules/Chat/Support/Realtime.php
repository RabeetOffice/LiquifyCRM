<?php

declare(strict_types=1);

namespace App\Modules\Chat\Support;

use App\Modules\Chat\Models\ChatVisitor;
use App\Modules\Meta\Models\Conversation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Instant delivery for the live chat through Ably's free tier (user decision, 4 Nov 2026; docs/LIVECHAT_PLAN.md).
 * The hosting cannot hold WebSocket connections, so Ably holds them: the server sends one tiny signal, "something
 * changed", on a channel, and the browsers listening on it (the visitor's chat box, the team's open pages) ask for
 * the real state at once. No chat text, name or address ever goes to Ably, and the channels' names are random-looking
 * and can be heard only with a token this server signed for that one channel.
 *
 * Everything here is optional and fails quietly: without an ABLY_API_KEY, or when Ably cannot be reached, nothing
 * is sent and every screen carries on with its ordinary polling, a few seconds slower.
 */
final class Realtime
{
    /** Tokens last an hour; one is kept for 50 minutes and handed to everybody who may hear that channel. */
    private const TOKEN_MS = 3600000;

    private const TOKEN_CACHE_SECONDS = 3000;

    /** @var array<string, true> signals waiting for the end of the request that caused them */
    private static array $waiting = [];

    private static bool $registered = false;

    public static function enabled(): bool
    {
        return self::key() !== null;
    }

    /** The brand's chats changed: the team's open pages ask what is new. */
    public static function notifyBrand(int $brandId): void
    {
        self::signal(self::brandChannel($brandId));
    }

    /** What a visitor sees changed (an answer, a closed chat, the team typing): their chat box asks what is new. */
    public static function notifyVisitor(Conversation|int|null $conversation): void
    {
        $visitorId = $conversation instanceof Conversation ? $conversation->chat_visitor_id : ($conversation !== null
            ? Conversation::query()->withoutGlobalScopes()->whereKey($conversation)->value('chat_visitor_id')
            : null);

        if (! self::enabled() || $visitorId === null) {
            return;
        }

        $token = ChatVisitor::query()->withoutGlobalScopes()->whereKey($visitorId)->value('visitor_token');

        if (is_string($token)) {
            self::signal(self::visitorChannel($token));
        }
    }

    public static function brandChannel(int $brandId): string
    {
        return 'lq-b-'.$brandId;
    }

    /** Not guessable from the visitor's token: only the server can work it out. */
    public static function visitorChannel(string $visitorToken): string
    {
        return 'lq-v-'.substr(hash_hmac('sha256', 'chat-visitor-channel|'.$visitorToken, (string) config('app.key')), 0, 24);
    }

    /**
     * The address a browser opens to hear one channel (Ably's server-sent events, no library needed), with a token
     * signed for that channel alone and for listening only. Null when Ably is off or does not answer.
     */
    public static function listenUrl(string $channel): ?string
    {
        $token = self::token([$channel => ['subscribe']]);

        if ($token === null) {
            return null;
        }

        return self::base().'/event-stream?channels='.rawurlencode($channel).'&v=1.2&accessToken='.rawurlencode($token);
    }

    /** Seconds after which a page asks for a fresh address (the token behind it expires after an hour). */
    public static function refreshInSeconds(): int
    {
        return 2700;
    }

    /**
     * An Ably token for these capabilities, made from a signed request (the key's secret never leaves this server).
     * The spec: https://ably.com/docs/api/token-request-spec
     *
     * @param  array<string, list<string>>  $capability
     */
    public static function token(array $capability): ?string
    {
        $key = self::key();

        if ($key === null) {
            return null;
        }

        ksort($capability);
        $capability = array_map(function (array $operations): array {
            sort($operations);

            return $operations;
        }, $capability);
        $capabilityText = (string) json_encode($capability, JSON_UNESCAPED_SLASHES);

        return Cache::remember('chat:ably-token:'.md5($capabilityText), self::TOKEN_CACHE_SECONDS, function () use ($key, $capabilityText): ?string {
            $request = self::signedRequest($key, $capabilityText, (int) round(microtime(true) * 1000), Str::random(24));

            try {
                $response = Http::timeout(4)->acceptJson()->asJson()->post(self::base().'/keys/'.rawurlencode($key['name']).'/requestToken', $request);
            } catch (Throwable $exception) {
                self::failed($exception->getMessage());

                return null;
            }

            if (! $response->successful() || ! is_string($response->json('token'))) {
                self::failed('token request answered '.$response->status());

                return null;
            }

            return $response->json('token');
        });
    }

    /**
     * The TokenRequest as Ably expects it: the fields in a fixed order, each followed by a newline, signed with
     * HMAC-SHA-256 and the key's secret, then Base64.
     *
     * @param  array{name: string, secret: string}  $key
     * @return array{keyName: string, ttl: int, capability: string, timestamp: int, nonce: string, mac: string}
     */
    public static function signedRequest(array $key, string $capabilityText, int $timestamp, string $nonce): array
    {
        $text = $key['name']."\n".self::TOKEN_MS."\n".$capabilityText."\n"."\n".$timestamp."\n".$nonce."\n";

        return [
            'keyName' => $key['name'],
            'ttl' => self::TOKEN_MS,
            'capability' => $capabilityText,
            'timestamp' => $timestamp,
            'nonce' => $nonce,
            'mac' => base64_encode(hash_hmac('sha256', $text, $key['secret'], true)),
        ];
    }

    /** Send one signal now (a command or a test), or when the request that caused it has been answered. */
    private static function signal(string $channel): void
    {
        if (! self::enabled()) {
            return;
        }

        // The person's own request is not kept waiting for Ably.
        if (app()->runningInConsole()) {
            self::publish($channel);

            return;
        }

        self::$waiting[$channel] = true;

        if (! self::$registered) {
            self::$registered = true;
            app()->terminating(function (): void {
                $channels = array_keys(self::$waiting);
                self::$waiting = [];

                foreach ($channels as $channel) {
                    self::publish($channel);
                }
            });
        }
    }

    private static function publish(string $channel): void
    {
        $key = self::key();

        if ($key === null) {
            return;
        }

        try {
            $response = Http::timeout(3)->withBasicAuth($key['name'], $key['secret'])->asJson()->post(self::base().'/channels/'.rawurlencode($channel).'/messages', ['name' => 'changed', 'data' => '1']);

            if (! $response->successful()) {
                self::failed('publish answered '.$response->status());
            }
        } catch (Throwable $exception) {
            self::failed($exception->getMessage());
        }
    }

    /** Ably being away is not an error of ours: a line in the log once a minute, and the screens poll as before. */
    private static function failed(string $why): void
    {
        if (Cache::add('chat:ably-failed', true, 60)) {
            Log::warning('Ably (live chat signals): '.$why);
        }
    }

    /** @return array{name: string, secret: string}|null */
    private static function key(): ?array
    {
        $key = (string) config('liquify.realtime.ably_key');

        return preg_match('/^([A-Za-z0-9_-]+\.[A-Za-z0-9_-]+):(.+)$/', $key, $found) === 1 ? ['name' => $found[1], 'secret' => $found[2]] : null;
    }

    /** Ably's address; only a test against a stand-in server changes the host or the scheme. */
    private static function base(): string
    {
        return (string) config('liquify.realtime.ably_scheme', 'https').'://'.(string) config('liquify.realtime.ably_host', 'main.realtime.ably.net');
    }
}
