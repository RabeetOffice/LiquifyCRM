<?php

declare(strict_types=1);

namespace App\Modules\Sites\Enums;

use App\Modules\Core\Contracts\HasColor;
use App\Modules\Core\Contracts\HasLabel;

/** The small platform badge on the Websites screen. */
enum SitePlatform: string implements HasColor, HasLabel
{
    case Php = 'php';
    case WordPress = 'wordpress';
    case Elementor = 'elementor';

    public function getLabel(): string
    {
        return match ($this) {
            self::Php => 'PHP',
            self::WordPress => 'WordPress',
            self::Elementor => 'Elementor',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Php => 'gray',
            self::WordPress => 'info',
            self::Elementor => 'primary',
        };
    }
}
