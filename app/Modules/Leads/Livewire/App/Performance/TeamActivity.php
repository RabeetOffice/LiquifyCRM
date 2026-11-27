<?php

declare(strict_types=1);

namespace App\Modules\Leads\Livewire\App\Performance;

use App\Modules\Leads\Livewire\Concerns\PerformancePage;
use App\Modules\Leads\Support\TeamPerformance;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Performance › Activity: what each person did in the month (notes, follow-ups done and left overdue, chat
 * replies, stage moves, leads taken).
 */
#[Layout('components.layouts.app')]
#[Title('Activity')]
final class TeamActivity extends Component
{
    use PerformancePage;

    public function render(): View
    {
        $month = $this->shownMonth();

        return view('leads::livewire.app.performance.activity', [
            'rows' => $this->rowsWith(TeamPerformance::activity((int) $this->brand->getKey(), $this->peopleIds(), $month), 'notes'),
            'months' => $this->monthOptions(),
            'shown' => $month,
        ]);
    }
}
