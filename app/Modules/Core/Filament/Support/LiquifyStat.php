<?php

declare(strict_types=1);

namespace App\Modules\Core\Filament\Support;

use Closure;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Dashboard card from the design section: an icon in a soft orange-tint
 * tile, the number large, the label muted, an optional change pill and an
 * optional footer link. The first card on a dashboard can be the "hero" on
 * a Liquify Orange face.
 */
final class LiquifyStat extends Stat
{
    protected string $view = 'core::filament.widgets.stat';

    protected bool|Closure $isHero = false;

    protected string|Closure|null $linkLabel = null;

    protected string|Closure|null $delta = null;

    protected bool|Closure|null $deltaIsPositive = null;

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

    /** A change against the previous period, e.g. "+12%" with up = true. */
    public function delta(string|Closure|null $text, bool|Closure|null $positive = null): static
    {
        $this->delta = $text;
        $this->deltaIsPositive = $positive;

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

    public function getDelta(): ?string
    {
        return $this->evaluate($this->delta);
    }

    public function getDeltaIsPositive(): ?bool
    {
        return $this->evaluate($this->deltaIsPositive);
    }
}
