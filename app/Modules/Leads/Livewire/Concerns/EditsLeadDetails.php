<?php

declare(strict_types=1);

namespace App\Modules\Leads\Livewire\Concerns;

use App\Modules\Auth\Models\User;
use App\Modules\Leads\Actions\UpdateLeadDetails;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Support\ContactVisibility;

/**
 * The "Edit details" dialog of a lead, on the lead panel and in the inbox
 * (the `lead-details` partial). The screen says which lead it is about with
 * detailsLead(), and needs WithToasts and AuthorizesRequests.
 *
 * A person who sees email and phone masked does not get them shown here
 * either: the two fields start empty, and are only changed when typed in.
 */
trait EditsLeadDetails
{
    public bool $showDetails = false;

    /** @var array<string, string> */
    public array $details = ['name' => '', 'email' => '', 'phone' => '', 'company' => '', 'address' => '', 'service' => ''];

    public bool $detailsContactVisible = true;

    /** The lead being edited, or null when there is none in reach. */
    abstract protected function detailsLead(): ?Lead;

    public function openDetails(): void
    {
        $lead = $this->detailsLead();

        if ($lead === null) {
            return;
        }

        $this->authorize('update', $lead);

        /** @var User $user */
        $user = auth()->user();

        $this->detailsContactVisible = app(ContactVisibility::class)->canSeeFull($user, $lead);
        $this->details = [
            'name' => (string) $lead->name,
            'email' => $this->detailsContactVisible ? (string) $lead->email : '',
            'phone' => $this->detailsContactVisible ? (string) $lead->phone : '',
            'company' => (string) ($lead->fields['company'] ?? ''),
            'address' => (string) ($lead->fields['address'] ?? ''),
            'service' => (string) ($lead->fields['service'] ?? ''),
        ];

        $this->resetValidation();
        $this->showDetails = true;
    }

    public function saveDetails(UpdateLeadDetails $update): void
    {
        $lead = $this->detailsLead();

        if ($lead === null) {
            $this->showDetails = false;

            return;
        }

        $this->authorize('update', $lead);

        /** @var array<string, string|null> $details */
        $details = $this->validate([
            'details.name' => ['required', 'string', 'max:160'],
            'details.email' => ['nullable', 'email', 'max:190'],
            'details.phone' => ['nullable', 'string', 'max:40'],
            'details.company' => ['nullable', 'string', 'max:160'],
            'details.address' => ['nullable', 'string', 'max:500'],
            'details.service' => ['nullable', 'string', 'max:120'],
        ], attributes: [
            'details.name' => 'name', 'details.email' => 'email', 'details.phone' => 'phone',
            'details.company' => 'company', 'details.address' => 'address', 'details.service' => 'service',
        ])['details'];

        // Masked and left empty means "leave it as it is", not "clear it".
        if (! $this->detailsContactVisible) {
            foreach (['email', 'phone'] as $key) {
                if (blank($details[$key] ?? null)) {
                    unset($details[$key]);
                }
            }
        }

        /** @var User $user */
        $user = auth()->user();

        $update->handle($lead, $details, $user);

        $this->showDetails = false;
        $this->dispatch('lead-updated', id: $lead->getKey());
        $this->toastSuccess('Details saved');
    }
}
