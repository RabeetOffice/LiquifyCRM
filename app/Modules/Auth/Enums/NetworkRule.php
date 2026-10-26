<?php

declare(strict_types=1);

namespace App\Modules\Auth\Enums;

use App\Modules\Core\Contracts\HasLabel;

/** Where somebody may sign in from: any internet connection, or only the office's addresses. */
enum NetworkRule: string implements HasLabel
{
    case Anywhere = 'anywhere';
    case Office = 'office';

    public function getLabel(): string
    {
        return match ($this) {
            self::Anywhere => 'Anywhere',
            self::Office => 'Office network only',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Anywhere => 'From any internet connection: the office, home or a phone\'s data.',
            self::Office => 'Only from the office internet addresses listed below. Elsewhere sign-in is refused.',
        };
    }
}
