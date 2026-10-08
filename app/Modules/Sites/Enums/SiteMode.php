<?php

declare(strict_types=1);

namespace App\Modules\Sites\Enums;

use App\Modules\Core\Contracts\HasLabel;

/** How the leads of a website reach LiquifyCRM. */
enum SiteMode: string implements HasLabel
{
    case Push = 'push';
    case Pull = 'pull';

    public function getLabel(): string
    {
        return match ($this) {
            self::Push => 'The website sends each lead',
            self::Pull => 'LiquifyCRM reads new leads every 5 minutes',
        };
    }
}
