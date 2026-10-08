<?php

declare(strict_types=1);

namespace App\Modules\Leads\Exceptions;

use RuntimeException;

final class RevealLimitReached extends RuntimeException
{
    public static function perHour(int $limit): self
    {
        return new self("You have revealed {$limit} contacts in the last hour. Try again later or ask your Manager.");
    }
}
