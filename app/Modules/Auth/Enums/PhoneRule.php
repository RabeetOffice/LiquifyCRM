<?php

declare(strict_types=1);

namespace App\Modules\Auth\Enums;

use App\Modules\Core\Contracts\HasLabel;

/** Whether somebody may use LiquifyCRM on a phone or tablet. */
enum PhoneRule: string implements HasLabel
{
    case Any = 'any';
    case Approved = 'approved';
    case None = 'none';

    public function getLabel(): string
    {
        return match ($this) {
            self::Any => 'Any phone',
            self::Approved => 'Only phones you approve',
            self::None => 'No phones',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Any => 'Phones and tablets work like a computer. You can still block one phone.',
            self::Approved => 'The first sign-in on a phone waits for an Admin to approve that phone. Approve the two or three that should work.',
            self::None => 'Phones and tablets are refused. Only computers can sign in.',
        };
    }
}
