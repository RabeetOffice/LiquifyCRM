<?php

declare(strict_types=1);

namespace App\Modules\Chat\Support;

use Illuminate\Support\Str;

/**
 * The visitor's browser holds a random token and its signature (an HMAC of the token with the app key). The
 * signature is what proves the token was issued here, so nobody can invent tokens or guess another
 * visitor's: knowing a token is not enough without the signature that goes with it.
 */
final class VisitorToken
{
    /** @return array{token: string, sig: string} */
    public static function issue(): array
    {
        $token = (string) Str::uuid();

        return ['token' => $token, 'sig' => self::sign($token)];
    }

    public static function sign(string $token): string
    {
        return substr(hash_hmac('sha256', 'chat-visitor|'.$token, (string) config('app.key')), 0, 40);
    }

    public static function valid(?string $token, ?string $sig): bool
    {
        return is_string($token) && is_string($sig)
            && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $token) === 1
            && hash_equals(self::sign($token), $sig);
    }
}
