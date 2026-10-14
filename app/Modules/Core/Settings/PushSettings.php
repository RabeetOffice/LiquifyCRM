<?php

declare(strict_types=1);

namespace App\Modules\Core\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * The key pair that signs desktop alerts sent with LiquifyCRM closed (Web
 * Push, "VAPID"). Made by PushKeys the first time a page asks for it; the
 * private half is stored encrypted and never leaves the server.
 */
final class PushSettings extends Settings
{
    public ?string $public_key;

    public ?string $private_key;

    public static function group(): string
    {
        return 'push';
    }

    /** @return list<string> */
    public static function encrypted(): array
    {
        return ['private_key'];
    }
}
