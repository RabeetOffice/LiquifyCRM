<?php

declare(strict_types=1);

namespace App\Modules\Auth\Enums;

use App\Modules\Core\Contracts\HasColor;
use App\Modules\Core\Contracts\HasLabel;

/**
 * A phone or tablet somebody signed in on. Seen: used while that person
 * could use any phone, never decided on. Waiting: their phones need an
 * Admin's approval and this one has none yet.
 */
enum DeviceStatus: string implements HasColor, HasLabel
{
    case Seen = 'seen';
    case Waiting = 'waiting';
    case Approved = 'approved';
    case Blocked = 'blocked';

    public function getLabel(): string
    {
        return match ($this) {
            self::Seen => 'Used',
            self::Waiting => 'Waiting for approval',
            self::Approved => 'Approved',
            self::Blocked => 'Blocked',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Seen => 'gray',
            self::Waiting => 'warning',
            self::Approved => 'success',
            self::Blocked => 'danger',
        };
    }
}
