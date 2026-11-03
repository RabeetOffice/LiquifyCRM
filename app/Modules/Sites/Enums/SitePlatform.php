<?php

declare(strict_types=1);

namespace App\Modules\Sites\Enums;

use App\Modules\Core\Contracts\HasColor;
use App\Modules\Core\Contracts\HasLabel;

/**
 * What a website is built with: the badge on the Websites screen and the
 * "Website type" an Admin chooses. A custom PHP website is read through
 * liquify.php; an Elementor form sends each lead to a webhook.
 */
enum SitePlatform: string implements HasColor, HasLabel
{
    case Php = 'php';
    case WordPress = 'wordpress';
    case Elementor = 'elementor';

    public function getLabel(): string
    {
        return match ($this) {
            self::Php => 'Custom PHP',
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

    /** The choice in the "Website type" list. */
    public function choiceLabel(): string
    {
        return match ($this) {
            self::Php => 'Custom PHP website',
            self::WordPress => 'WordPress',
            self::Elementor => 'WordPress with an Elementor form',
        };
    }
}
