<?php

declare(strict_types=1);

namespace App\Modules\Leads\Exceptions;

use RuntimeException;

/**
 * A pipeline stage that still holds leads is never removed (user request,
 * 12 Nov 2026): the leads would have nowhere to be. Leads in the Bin count,
 * because restoring one puts it back in its stage.
 */
final class StageInUse extends RuntimeException
{
    public static function holding(string $stage, int $leads, int $inBin = 0): self
    {
        $count = $leads === 1 ? '1 lead is' : $leads.' leads are';
        $bin = $inBin > 0 ? ' '.($inBin === $leads ? ($leads === 1 ? 'It is in the Bin: restore it and move it, or delete it forever.' : 'They are in the Bin: restore and move them, or delete them forever.') : $inBin.' of them in the Bin count too.') : '';

        return new self("{$count} in {$stage}. Move ".($leads === 1 ? 'it' : 'them').' to another stage first, then remove it.'.$bin);
    }

    public static function fixed(string $stage): self
    {
        return new self("{$stage} stays: new leads, sales and closed leads depend on it. You can rename it or change its colour.");
    }
}
