<?php

declare(strict_types=1);

namespace App\Modules\Core\Contracts;

/** An enum with a plain-words label for screens and pills. */
interface HasLabel
{
    public function getLabel(): string;
}
