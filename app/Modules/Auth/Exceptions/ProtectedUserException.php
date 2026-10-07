<?php

declare(strict_types=1);

namespace App\Modules\Auth\Exceptions;

use RuntimeException;

final class ProtectedUserException extends RuntimeException
{
    public static function lastSuperAdmin(): self
    {
        return new self('The last Super Admin can never be removed or demoted.');
    }

    public static function protectedAccount(): self
    {
        return new self('Super Admin accounts can only be changed by that Super Admin.');
    }
}
