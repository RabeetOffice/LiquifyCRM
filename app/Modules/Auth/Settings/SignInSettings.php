<?php

declare(strict_types=1);

namespace App\Modules\Auth\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * The sign-in rules for everybody, chosen on Sign-in security: where people
 * may sign in from (NetworkRule) and whether on a phone or tablet
 * (PhoneRule). A person's own rule on People › Edit takes their place.
 */
final class SignInSettings extends Settings
{
    public string $network;

    public string $phones;

    public static function group(): string
    {
        return 'sign_in';
    }
}
