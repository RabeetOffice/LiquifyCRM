<?php

declare(strict_types=1);

namespace App\Modules\Core\Contracts;

/** An enum with a semantic colour name: primary, gray, info, success, warning or danger. */
interface HasColor
{
    public function getColor(): string;
}
