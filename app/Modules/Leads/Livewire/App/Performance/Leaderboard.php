<?php

declare(strict_types=1);

namespace App\Modules\Leads\Livewire\App\Performance;

use App\Modules\Leads\Livewire\Concerns\PerformancePage;
use App\Modules\Sales\Support\SalesReport;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Performance › Leaderboard: the people of the month by % of target, then by money received (SalesReport::agentRows,
 * the same order as the Sales dashboard), with their sales, leads and time to first contact.
 */
#[Layout('components.layouts.app')]
#[Title('Leaderboard')]
final class Leaderboard extends Component
{
    use PerformancePage;

    public function render(): View
    {
        $month = $this->shownMonth();
        $report = SalesReport::forBrand($this->brand, $this->user(), $month);

        return view('leads::livewire.app.performance.leaderboard', [
            'rows' => $report->agentRows(),
            'currency' => $report->currency(),
            'months' => $this->monthOptions(),
            'shown' => $month,
        ]);
    }
}
