<?php

declare(strict_types=1);

namespace App\Modules\Core\Support;

/**
 * Text that outsiders wrote (a website form, a Facebook lead, a log line)
 * going into a CSV or Excel file. A cell starting with = + - @ would be read
 * as a formula when the file is opened, and could send other cells to an
 * outside address, so such text gets a leading apostrophe: the spreadsheet
 * then shows it as text (security audit, 28 Oct 2026). A phone number such as
 * "+44 7700 900123" carries no formula and is left alone.
 */
final class SpreadsheetText
{
    public static function safe(?string $text): ?string
    {
        if ($text === null || $text === '' || ! preg_match('~^[=+\-@\t\r]~', $text)) {
            return $text;
        }

        if (preg_match('~^\+[\d\s().-]+$~', $text)) {
            return $text;
        }

        return "'".$text;
    }
}
