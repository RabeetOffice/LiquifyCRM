<?php

declare(strict_types=1);

namespace App\Modules\Leads\Livewire\App;

use App\Modules\Core\Support\ViewerTime;
use App\Modules\Leads\Livewire\Concerns\PerformancePage;
use App\Modules\Leads\Support\LeadTakes;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Performance › Leads taken (user request, 27 Nov 2026): one row per person with how many leads they took with
 * the Take button today, this month, last month and ever, and what became of them. Each number opens
 * the Leads list with "Taken by". Admins see everybody of the brand, a Manager the people of their teams.
 */
#[Layout('components.layouts.app')]
#[Title('Leads taken')]
final class LeadsTaken extends Component
{
    use PerformancePage;

    public function render(): View
    {
        $people = $this->people();
        $counts = LeadTakes::perPerson((int) $this->brand->getKey(), array_map(fn (array $person): int => $person['id'], $people));

        $rows = collect($people)
            ->map(fn (array $person): array => $person + ['counts' => $counts[$person['id']]])
            ->sortBy([fn (array $a, array $b): int => $b['counts']['month'] <=> $a['counts']['month'], fn (array $a, array $b): int => $b['counts']['all'] <=> $a['counts']['all']])
            ->values();

        $total = fn (string $key): int => (int) $rows->sum(fn (array $row): int => $row['counts'][$key]);

        return view('leads::livewire.app.leads-taken', [
            'rows' => $rows,
            'totals' => ['today' => $total('today'), 'month' => $total('month'), 'last_month' => $total('last_month'), 'all' => $total('all')],
            'month' => ViewerTime::now()->format('F'),
            'lastMonth' => ViewerTime::now()->subMonthNoOverflow()->format('F'),
        ]);
    }
}
