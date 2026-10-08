<?php

declare(strict_types=1);

namespace App\Modules\Core\Enums;

use App\Modules\Core\Contracts\HasColor;
use App\Modules\Core\Contracts\HasLabel;

enum MembershipStatus: string implements HasColor, HasLabel
{
    case Active = 'active';
    case Suspended = 'suspended';

    public function getLabel(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Suspended => 'Suspended',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Suspended => 'gray',
        };
    }
}
