<?php

declare(strict_types=1);

namespace App\Modules\Core\Contracts;

/** An enum with a Heroicon name, e.g. heroicon-o-globe-alt. */
interface HasIcon
{
    public function getIcon(): string;
}
