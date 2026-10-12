<?php

declare(strict_types=1);

namespace App\Modules\Leads\Livewire\Concerns;

use App\Modules\Auth\Models\User;
use App\Modules\Leads\Models\Lead;

/**
 * The lead slide-over on the list and on the pipeline: which lead is open,
 * and only ever one the person may see.
 */
trait PreviewsLead
{
    public ?int $open = null;

    public function openLead(int $leadId): void
    {
        $this->open = $leadId;
    }

    public function closeLead(): void
    {
        $this->open = null;
    }

    /** A lead that is gone or out of reach closes the slide-over instead of breaking the screen. */
    protected function previewLead(User $user): ?Lead
    {
        if ($this->open === null) {
            return null;
        }

        $lead = Lead::query()->visibleTo($user)->find($this->open);

        if ($lead === null) {
            $this->open = null;
        }

        return $lead;
    }
}
