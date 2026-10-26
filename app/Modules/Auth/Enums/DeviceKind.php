<?php

declare(strict_types=1);

namespace App\Modules\Auth\Enums;

use App\Modules\Core\Contracts\HasLabel;

/** What somebody signs in on, as far as their browser tells (Device::of). */
enum DeviceKind: string implements HasLabel
{
    case Computer = 'computer';
    case Phone = 'phone';
    case Tablet = 'tablet';

    public function getLabel(): string
    {
        return match ($this) {
            self::Computer => 'Computer',
            self::Phone => 'Phone',
            self::Tablet => 'Tablet',
        };
    }

    /** Phones and tablets follow the phone rule; a computer never does. */
    public function isMobile(): bool
    {
        return $this !== self::Computer;
    }

    public function icon(): string
    {
        return match ($this) {
            self::Computer => 'heroicon-o-computer-desktop',
            self::Phone => 'heroicon-o-device-phone-mobile',
            self::Tablet => 'heroicon-o-device-tablet',
        };
    }
}
