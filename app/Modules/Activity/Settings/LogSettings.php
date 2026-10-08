<?php

declare(strict_types=1);

namespace App\Modules\Activity\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * Log retention, changeable on the Logs screen: how long entries stay
 * searchable in the database, and how long the daily archive files are kept.
 */
final class LogSettings extends Settings
{
    public const DATABASE_OPTIONS = [7, 15, 30, 60, 90];

    public const ARCHIVE_OPTIONS = [90, 180, 365];

    /** The newest days can never be deleted by hand. */
    public const PROTECTED_DAYS = 7;

    public int $database_days;

    public int $archive_days;

    public static function group(): string
    {
        return 'logs';
    }
}
