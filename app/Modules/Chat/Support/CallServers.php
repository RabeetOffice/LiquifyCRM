<?php

declare(strict_types=1);

namespace App\Modules\Chat\Support;

/**
 * The servers a browser uses to set up a voice call: two that tell it its own public address (STUN, free and
 * without sign-in) and, when the .env names one, a relay (TURN) for the calls that cannot connect directly, about
 * one in five. The relay's sign-in is handed to a browser only for a call, never with the chat box's page.
 */
final class CallServers
{
    /** @return list<array{urls: string|list<string>, username?: string, credential?: string}> */
    public static function list(): array
    {
        $servers = [['urls' => ['stun:stun.cloudflare.com:3478', 'stun:stun.l.google.com:19302']]];

        $host = self::host();
        $username = (string) config('liquify.chat.turn.username');
        $password = (string) config('liquify.chat.turn.password');

        if ($host !== null && $username !== '' && $password !== '') {
            $servers[] = ['urls' => ['turn:'.$host.'?transport=udp', 'turn:'.$host.'?transport=tcp'], 'username' => $username, 'credential' => $password];
        }

        return $servers;
    }

    public static function hasRelay(): bool
    {
        return count(self::list()) > 1;
    }

    /** "relay.example.com:3478" from the .env, with or without "turn:" in front; nothing else is accepted. */
    private static function host(): ?string
    {
        $value = preg_replace('/^turns?:/i', '', trim((string) config('liquify.chat.turn.url'))) ?? '';

        return preg_match('/^[A-Za-z0-9.-]{3,190}(:[0-9]{2,5})?$/', $value) === 1 ? $value : null;
    }
}
