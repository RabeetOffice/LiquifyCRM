<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support;

/**
 * Achieved against a monthly target. Revenue leads when a revenue target is
 * set, otherwise the number of sales. Above 100% it becomes the
 * "Over-achieved" badge: "132%, £4,800 over target".
 */
final class Progress
{
    public function __construct(
        public readonly float $revenue,
        public readonly int $count,
        public readonly ?float $targetRevenue,
        public readonly ?int $targetCount,
        public readonly string $currency,
    ) {}

    public function hasTarget(): bool
    {
        return ($this->targetRevenue !== null && $this->targetRevenue > 0) || ($this->targetCount !== null && $this->targetCount > 0);
    }

    public function percent(): ?int
    {
        if ($this->targetRevenue !== null && $this->targetRevenue > 0) {
            return (int) round($this->revenue / $this->targetRevenue * 100);
        }

        if ($this->targetCount !== null && $this->targetCount > 0) {
            return (int) round($this->count / $this->targetCount * 100);
        }

        return null;
    }

    public function isOver(): bool
    {
        return ($this->percent() ?? 0) > 100;
    }

    /** Width of the bar, never beyond the track. */
    public function barWidth(): int
    {
        return max(0, min(100, $this->percent() ?? 0));
    }

    /** "£3,200 of £5,000" or "4 of 10 sales". */
    public function label(): string
    {
        if ($this->targetRevenue !== null && $this->targetRevenue > 0) {
            return Money::format($this->revenue, $this->currency).' of '.Money::format($this->targetRevenue, $this->currency);
        }

        if ($this->targetCount !== null && $this->targetCount > 0) {
            return $this->count.' of '.$this->targetCount.' '.str('sale')->plural($this->targetCount);
        }

        return 'No target set';
    }

    /** The line under the bar: "£6,574 to go · 3 sales so far", or "3 of 5 sales" when the number of sales is a target too. */
    public function summary(): string
    {
        $parts = [];

        if ($this->targetRevenue !== null && $this->targetRevenue > 0 && $this->revenue < $this->targetRevenue) {
            $parts[] = Money::format($this->targetRevenue - $this->revenue, $this->currency).' to go';
        }

        $parts[] = $this->targetCount !== null && $this->targetCount > 0
            ? $this->count.' of '.$this->targetCount.' '.str('sale')->plural($this->targetCount)
            : $this->count.' '.str('sale')->plural($this->count).' so far';

        return implode(' · ', $parts);
    }

    /** What was sold, for a line with no target: "£5,926 sold · 3 sales". */
    public function achieved(): string
    {
        return $this->count === 0 ? 'Nothing sold yet' : Money::format($this->revenue, $this->currency).' sold · '.$this->count.' '.str('sale')->plural($this->count);
    }

    /** "132%, £4,800 over target". */
    public function overLabel(): string
    {
        if ($this->targetRevenue !== null && $this->targetRevenue > 0) {
            return $this->percent().'%, '.Money::format($this->revenue - $this->targetRevenue, $this->currency).' over target';
        }

        $extra = $this->count - (int) $this->targetCount;

        return $this->percent().'%, '.$extra.' '.str('sale')->plural($extra).' over target';
    }
}
