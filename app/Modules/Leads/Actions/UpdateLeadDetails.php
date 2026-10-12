<?php

declare(strict_types=1);

namespace App\Modules\Leads\Actions;

use App\Modules\Auth\Models\User;
use App\Modules\Core\Support\Audit;
use App\Modules\Leads\Models\Lead;
use App\Modules\Meta\Models\Conversation;

/**
 * What the team learns about a person while working a lead: the right name,
 * an email, a phone number, a company, an address, what they want. It stays
 * on the lead (and so goes with it when it becomes a client), and the change
 * is one line on the lead's timeline.
 */
final class UpdateLeadDetails
{
    /** Facts kept in the lead's extra fields, with the words shown on screen. */
    public const EXTRAS = ['company' => 'Company', 'address' => 'Address', 'service' => 'Service'];

    /**
     * Only the keys that are present are changed; an empty value clears one
     * (except the name: a lead always has one).
     *
     * @param  array<string, string|null>  $details  name, email, phone, company, address, service
     */
    public function handle(Lead $lead, array $details, ?User $by = null): Lead
    {
        $changed = [];

        foreach (['name', 'email', 'phone'] as $key) {
            if (! array_key_exists($key, $details)) {
                continue;
            }

            $value = $this->clean($details[$key]);
            $value = $key === 'email' && $value !== null ? mb_strtolower($value) : $value;

            if (($key === 'name' && $value === null) || $lead->getAttribute($key) === $value) {
                continue;
            }

            $lead->setAttribute($key, $value);
            $changed[] = ucfirst($key);
        }

        $fields = (array) ($lead->fields ?? []);

        foreach (self::EXTRAS as $key => $label) {
            if (! array_key_exists($key, $details)) {
                continue;
            }

            $value = $this->clean($details[$key]);

            if (($fields[$key] ?? null) === $value) {
                continue;
            }

            if ($value === null) {
                unset($fields[$key]);
            } else {
                $fields[$key] = $value;
            }

            $changed[] = $label;
        }

        if ($changed === []) {
            return $lead;
        }

        $lead->fields = $fields === [] ? null : $fields;
        $lead->last_activity_at = now();
        $lead->save();

        // The chat is listed under the name the lead is known by.
        if (in_array('Name', $changed, true)) {
            Conversation::query()->where('lead_id', $lead->getKey())->get()
                ->each(fn (Conversation $conversation): bool => $conversation->update(['contact_name' => $lead->name]));
        }

        Audit::log('leads.details_updated', $lead, ['changed' => $changed], 'Details updated: '.mb_strtolower(implode(', ', $changed)), causer: $by, logName: 'leads');

        return $lead;
    }

    private function clean(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
