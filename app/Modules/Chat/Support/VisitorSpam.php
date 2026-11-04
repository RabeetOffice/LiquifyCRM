<?php

declare(strict_types=1);

namespace App\Modules\Chat\Support;

/**
 * What a visitor's message or details are checked against before anything is kept: too long, too many links
 * (the usual shape of spam), a throw-away email address.
 */
final class VisitorSpam
{
    public const MAX_MESSAGE = 2000;

    public const MAX_LINKS = 2;

    /** Mail services that give out addresses nobody keeps. Not complete: it stops the common ones. */
    private const DISPOSABLE = [
        'mailinator.com', 'guerrillamail.com', 'guerrillamail.net', 'sharklasers.com', '10minutemail.com', '10minutemail.net',
        'tempmail.com', 'temp-mail.org', 'yopmail.com', 'trashmail.com', 'getnada.com', 'dispostable.com', 'maildrop.cc',
        'throwawaymail.com', 'fakeinbox.com', 'mailnesia.com', 'mintemail.com', 'spamgourmet.com', 'tempinbox.com',
    ];

    public static function tooManyLinks(string $text): bool
    {
        return preg_match_all('~(?:https?://|www\.)\S+~i', $text) > self::MAX_LINKS;
    }

    public static function disposable(string $email): bool
    {
        $domain = mb_strtolower(substr((string) strrchr($email, '@'), 1));

        return in_array($domain, self::DISPOSABLE, true);
    }
}
