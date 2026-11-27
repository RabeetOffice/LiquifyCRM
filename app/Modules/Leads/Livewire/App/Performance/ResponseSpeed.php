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
 * Performance › Response speed: for each person's new leads of the month, how long until somebody had them and until
 * the first contact (the lead left New), how many were contacted within 15 minutes and how many still wait.
 */
#[Layout('components.layouts.app')]
#[Title('Response speed')]
final class ResponseSpeed extends Component
{
    use PerformancePage;

    public function render(): View
    {
        $month = $this->shownMonth();

        return view('leads::livewire.app.performance.speed', [
            'rows' => $this->rowsWith(TeamPerformance::speed((int) $this->brand->getKey(), $this->peopleIds(), $month), 'leads'),
            'months' => $this->monthOptions(),
            'shown' => $month,
        ]);
    }
}
