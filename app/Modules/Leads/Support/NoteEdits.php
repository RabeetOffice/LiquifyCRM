<?php

declare(strict_types=1);

namespace App\Modules\Leads\Support;

use App\Modules\Core\Support\KnownSchema;

/**
 * Correcting a note (user request, 4 Nov 2026). `lead_notes.edited_at` /
 * `edited_by` arrive with "Update system": until then notes cannot be edited
 * and nothing says "edited".
 */
final class NoteEdits
{
    private static bool $ready = false;

    public static function ready(): bool
    {
        return self::$ready = self::$ready || KnownSchema::column('lead_notes', 'edited_at');
    }
}
