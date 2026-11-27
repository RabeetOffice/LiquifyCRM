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
 * Performance › Conversion: each person's leads that arrived in the month, and how many became Won or Lost.
 */
#[Layout('components.layouts.app')]
#[Title('Conversion')]
final class Conversion extends Component
{
    use PerformancePage;

    public function render(): View
    {
        $month = $this->shownMonth();

        return view('leads::livewire.app.performance.conversion', [
            'rows' => $this->rowsWith(TeamPerformance::conversion((int) $this->brand->getKey(), $this->peopleIds(), $month), 'won'),
            'months' => $this->monthOptions(),
            'shown' => $month,
        ]);
    }
}
