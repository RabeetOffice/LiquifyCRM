<?php

declare(strict_types=1);

namespace App\Modules\Sites\Enums;

use App\Modules\Core\Contracts\HasColor;
use App\Modules\Core\Contracts\HasLabel;

/** Green, amber, red, or not checked. */
enum CheckStatus: string implements HasColor, HasLabel
{
    case Ok = 'ok';
    case Warn = 'warn';
    case Fail = 'fail';
    case Skip = 'skip';
    case Unknown = 'unknown';

    public function getLabel(): string
    {
        return match ($this) {
            self::Ok => 'Healthy',
            self::Warn => 'Needs a look',
            self::Fail => 'Problem',
            self::Skip => 'Not set up',
            self::Unknown => 'Not checked yet',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Ok => 'success',
            self::Warn => 'warning',
            self::Fail => 'danger',
            self::Skip, self::Unknown => 'gray',
        };
    }

    /** Higher is worse; used to pick the overall colour of a website. */
    public function severity(): int
    {
        return match ($this) {
            self::Fail => 3,
            self::Warn => 2,
            self::Ok => 1,
            self::Skip, self::Unknown => 0,
        };
    }
}
