<?php

declare(strict_types=1);

namespace App\Modules\Core\Filament\Support;

use Closure;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Dashboard card from the design section: an icon in a soft orange-tint
 * circle, the number large, the label muted, and an optional footer link.
 * The first card on a dashboard can be the "hero" on a Liquify Orange face.
 */
final class LiquifyStat extends Stat
{
    protected string $view = 'core::filament.widgets.stat';

    protected bool|Closure $isHero = false;

    protected string|Closure|null $linkLabel = null;

    public function hero(bool|Closure $condition = true): static
    {
        $this->isHero = $condition;

        return $this;
    }

    public function linkLabel(string|Closure|null $label): static
    {
        $this->linkLabel = $label;

        return $this;
    }

    public function isHero(): bool
    {
        return (bool) $this->evaluate($this->isHero);
    }

    public function getLinkLabel(): ?string
    {
        return $this->evaluate($this->linkLabel);
    }
}
