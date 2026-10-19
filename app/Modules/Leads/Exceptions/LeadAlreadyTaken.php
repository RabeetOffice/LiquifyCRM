<?php

declare(strict_types=1);

namespace App\Modules\Leads\Exceptions;

use RuntimeException;

final class LeadAlreadyTaken extends RuntimeException
{
    public static function by(?string $name): self
    {
        return new self(($name ?? 'Somebody').' took this lead a moment before you.');
    }
}
