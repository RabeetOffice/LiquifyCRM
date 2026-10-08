<?php

declare(strict_types=1);

namespace App\Modules\Leads\Livewire\App;

use App\Modules\Brands\Models\Brand;
use App\Modules\Leads\Models\Lead;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;

/** Full-page lead (used by notification links); the panel does the work. */
#[Layout('components.layouts.app')]
final class LeadShow extends Component
{
    use AuthorizesRequests;

    public Brand $brand;

    public Lead $lead;

    public function mount(Brand $brand, Lead $lead): void
    {
        $this->brand = $brand;
        $this->lead = $lead;
        $this->authorize('view', $lead);
    }

    #[On('lead-updated')]
    public function refresh(): void
    {
        $this->lead = $this->lead->fresh() ?? $this->lead;
    }

    #[On('lead-deleted')]
    public function deleted(): void
    {
        $this->redirectRoute('brand.leads.index', ['brand' => $this->brand->slug], navigate: true);
    }

    public function render(): View
    {
        return view('leads::livewire.app.lead-show')->title($this->lead->name);
    }
}
