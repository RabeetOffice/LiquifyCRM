<?php

declare(strict_types=1);

namespace App\Modules\Core\Enums;

use App\Modules\Core\Contracts\HasLabel;

/** How a department shares the leads it receives among its Agents. */
enum AssignmentMode: string implements HasLabel
{
    case Manual = 'manual';
    case RoundRobin = 'round_robin';

    public function getLabel(): string
    {
        return match ($this) {
            self::Manual => 'By hand',
            self::RoundRobin => 'Round robin',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::Manual => 'New leads wait in the queue until the Manager gives each one to an Agent.',
            self::RoundRobin => 'Each new lead goes straight to the next Agent in turn, so everyone gets the same number.',
        };
    }
}
