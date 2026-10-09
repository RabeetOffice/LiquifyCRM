<?php

declare(strict_types=1);

namespace App\Modules\Leads\Actions;

use App\Modules\Auth\Models\User;
use App\Modules\Brands\Models\Brand;
use App\Modules\Core\Support\Audit;
use App\Modules\Leads\Enums\LeadSource;
use App\Modules\Leads\Events\LeadCreated;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Models\LeadStatus;
use App\Modules\Leads\Support\LeadMatcher;
use App\Modules\Leads\Support\LeadNotifier;
use App\Modules\Leads\Support\PhoneNormalizer;
use App\Modules\Sales\Models\Client;
use Illuminate\Support\Facades\DB;

/**
 * Every channel ends here: normalise, de-duplicate inside the brand, put the
 * lead in New / Unassigned, write the timeline entry and notify. A repeat
 * enquiry from a known email or phone lands on the existing open lead's
 * timeline instead of creating a second lead.
 */
final class CreateLead
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(Brand $brand, array $data, ?User $by = null, bool $mergeDuplicates = true): LeadCreation
    {
        $data['email'] = filled($data['email'] ?? null) ? mb_strtolower(trim((string) $data['email'])) : null;
        $data['phone'] = PhoneNormalizer::e164($data['phone'] ?? null, $brand);
        $data['name'] = trim((string) ($data['name'] ?? '')) ?: ($data['email'] ?? $data['phone'] ?? 'Unknown');
        $source = ($data['source_type'] ?? null) instanceof LeadSource ? $data['source_type'] : LeadSource::Manual;

        return DB::transaction(function () use ($brand, $data, $by, $mergeDuplicates, $source): LeadCreation {
            $existing = $mergeDuplicates ? LeadMatcher::find($brand, $data['email'], $data['phone']) : null;

            if ($existing !== null) {
                Audit::log(
                    'leads.repeat_enquiry',
                    $existing,
                    ['source' => $source->value, 'message' => $data['message'] ?? null, 'fields' => $data['fields'] ?? []],
                    'Repeat enquiry from '.$existing->name,
                    brand: $brand,
                    causer: $by,
                    logName: 'leads',
                );

                $existing->touchActivity();

                return new LeadCreation($existing, wasMerged: true);
            }

            $attributes = [
                'brand_id' => $brand->getKey(),
                'source_type' => $source,
                'source_id' => $data['source_id'] ?? null,
                'external_id' => $data['external_id'] ?? null,
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'],
                'message' => $data['message'] ?? null,
                'status_id' => $data['status_id'] ?? LeadStatus::defaultFor($brand)?->getKey(),
                'department_id' => $data['department_id'] ?? null,
                'assigned_to' => $data['assigned_to'] ?? null,
                'assigned_at' => filled($data['assigned_to'] ?? null) ? now() : null,
                'imported_at' => $data['imported_at'] ?? null,
                'value' => $data['value'] ?? null,
                'currency' => $data['currency'] ?? null,
                'utm' => $data['utm'] ?? null,
                'fields' => $data['fields'] ?? null,
                'created_by' => $by?->getKey(),
                'client_id' => Client::match($brand, $data['email'], $data['phone'])?->getKey(),
            ];

            $lead = new Lead($attributes);

            // Imported and website leads keep their original date.
            if (isset($data['created_at'])) {
                $lead->created_at = $data['created_at'];
            }

            $lead->save();

            Audit::log(
                'leads.created',
                $lead,
                ['source' => $source->value, 'department_id' => $lead->department_id, 'assigned_to' => $lead->assigned_to],
                'Lead created from '.$source->getLabel(),
                brand: $brand,
                causer: $by,
                logName: 'leads',
            );

            LeadCreated::dispatch($lead);

            // Bulk imports of old leads stay quiet; nobody wants 2,000 emails.
            if ($data['silent'] ?? false) {
                return new LeadCreation($lead, wasMerged: false);
            }

            // A lead that arrives straight in a round-robin department goes to the next Agent in turn.
            if ($lead->assigned_to === null && $lead->department_id !== null && app(AutoAssignLead::class)->handle($lead, $by) !== null) {
                return new LeadCreation($lead, wasMerged: false);
            }

            if ($lead->assigned_to === null) {
                app(LeadNotifier::class)->newLead($lead);
            } elseif ($lead->assigned_to !== $by?->getKey()) {
                app(LeadNotifier::class)->assigned($lead, $lead->assignee, $by);
            }

            return new LeadCreation($lead, wasMerged: false);
        });
    }
}
