<?php

declare(strict_types=1);

namespace App\Modules\Meta\Exceptions;

use RuntimeException;

/** An error answered by the Graph API, or a Meta step that cannot continue. */
final class MetaException extends RuntimeException
{
    public function __construct(string $message, int $code = 0, public readonly ?int $subcode = null)
    {
        parent::__construct($message, $code);
    }

    /** Code 190: the token expired or was revoked, so Facebook must be connected again. */
    public function tokenExpired(): bool
    {
        return $this->getCode() === 190;
    }
}
