<?php

declare(strict_types=1);

namespace App\Modules\Leads\Exceptions;

use RuntimeException;

final class LeadIsWon extends RuntimeException
{
    public static function withSale(): self
    {
        return new self('This lead is Won with a recorded sale, so it stays in Won. If the sale was a mistake, an Admin corrects or removes it on the client\'s page.');
    }
}
