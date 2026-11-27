<?php

declare(strict_types=1);

namespace App\Modules\Leads\Livewire\App\Performance;

use App\Modules\Leads\Livewire\Concerns\PerformancePage;
use App\Modules\Leads\Support\LeadInsights;
use App\Modules\Leads\Support\TeamPerformance;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Performance › Lost reasons: why each person's leads were lost in the month, one column per reason.
 */
#[Layout('components.layouts.app')]
#[Title('Lost reasons')]
final class LostReasons extends Component
{
    use PerformancePage;

    public function render(): View
    {
        abort_unless(LeadInsights::ready(), 404);
        $month = $this->shownMonth();
        $counts = TeamPerformance::lostReasons((int) $this->brand->getKey(), $this->peopleIds(), $month);
        $rows = collect($this->people())
            ->map(fn (array $person): array => $person + ['counts' => $counts[$person['id']], 'total' => array_sum($counts[$person['id']])])
            ->sortByDesc('total')
            ->values();

        return view('leads::livewire.app.performance.lost', [
            'rows' => $rows,
            'reasons' => LeadInsights::LOST + ['not_given' => 'Not given'],
            'months' => $this->monthOptions(),
            'shown' => $month,
        ]);
    }
}
