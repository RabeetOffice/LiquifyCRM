<?php

declare(strict_types=1);

namespace App\Modules\Sales\Enums;

use App\Modules\Core\Contracts\HasColor;
use App\Modules\Core\Contracts\HasIcon;
use App\Modules\Core\Contracts\HasLabel;

/** Where a client stands after the first sale. Worked out from the client, never stored. */
enum ClientStage: string implements HasColor, HasIcon, HasLabel
{
    /** Won, not handed to an Upsell department yet. */
    case Front = 'front';

    /** In an Upsell department, nobody looks after them yet. */
    case Waiting = 'waiting';

    /** Someone looks after them. */
    case Active = 'active';

    case Closed = 'closed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Front => 'Not handed over',
            self::Waiting => 'Waiting for a person',
            self::Active => 'Active',
            self::Closed => 'Closed',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Front => 'gray',
            self::Waiting => 'warning',
            self::Active => 'success',
            self::Closed => 'gray',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Front => 'heroicon-m-arrow-right-circle',
            self::Waiting => 'heroicon-m-clock',
            self::Active => 'heroicon-m-check-badge',
            self::Closed => 'heroicon-m-lock-closed',
        };
    }
}
