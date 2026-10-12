<?php

declare(strict_types=1);

namespace App\Modules\Sales\Enums;

use App\Modules\Core\Contracts\HasColor;
use App\Modules\Core\Contracts\HasLabel;

/** A front sale wins a lead; an upsell is a later sale to the same client. */
enum SaleKind: string implements HasColor, HasLabel
{
    case Front = 'front';
    case Upsell = 'upsell';

    public function getLabel(): string
    {
        return match ($this) {
            self::Front => 'Front sale',
            self::Upsell => 'Upsell',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Front => 'info',
            self::Upsell => 'success',
        };
    }
}
