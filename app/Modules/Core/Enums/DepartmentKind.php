<?php

declare(strict_types=1);

namespace App\Modules\Core\Enums;

use App\Modules\Core\Contracts\HasColor;
use App\Modules\Core\Contracts\HasLabel;

/** What a department is for: winning new leads, or looking after the clients that were won. */
enum DepartmentKind: string implements HasColor, HasLabel
{
    case Sales = 'sales';
    case Upsell = 'upsell';

    public function getLabel(): string
    {
        return match ($this) {
            self::Sales => 'Front sales',
            self::Upsell => 'Upsell',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Sales => 'info',
            self::Upsell => 'success',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::Sales => 'Works new leads from the website and Facebook until the first sale.',
            self::Upsell => 'Looks after clients after the first sale: their project and every later sale.',
        };
    }
}
