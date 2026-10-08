<?php

declare(strict_types=1);

namespace App\Modules\Core\Enums;

use App\Modules\Core\Contracts\HasColor;
use App\Modules\Core\Contracts\HasLabel;

enum DepartmentRole: string implements HasColor, HasLabel
{
    case Manager = 'manager';
    case Agent = 'agent';

    public function getLabel(): string
    {
        return match ($this) {
            self::Manager => 'Manager',
            self::Agent => 'Agent',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Manager => 'info',
            self::Agent => 'success',
        };
    }
}
