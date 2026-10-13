<?php

declare(strict_types=1);

namespace App\Modules\Leads\Actions;

use App\Modules\Auth\Models\User;
use App\Modules\Brands\Models\Brand;
use App\Modules\Core\Support\Audit;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Support\LeadMatcher;
use App\Modules\Leads\Support\PhoneNormalizer;
use App\Modules\Sales\Models\Client;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * One person, one lead. `handle()` makes two leads of the same person one:
 * the chat, notes, follow-ups and history of the other move to the lead
 * that stays, which takes whatever it was missing. `join()` does the same
 * for an enquiry that arrives for a lead that is already there (a Facebook
 * lead form from somebody who is already chatting with the Page).
 */
final class MergeLeads
{
    /** Tables whose rows belong to a lead and move with it. */
    private const CHILDREN = ['lead_notes', 'tasks', 'conversations', 'inbound_events'];

    public function handle(Lead $keep, Lead $other, ?User $by = null): Lead
    {
        if ($keep->is($other) || (int) $keep->brand_id !== (int) $other->brand_id) {
            throw new InvalidArgumentException('These two leads cannot be merged.');
        }

        if (! $keep->isOpen() || ! $other->isOpen()) {
            throw new InvalidArgumentException('A lead that is won or lost cannot be merged.');
        }

        return DB::transaction(function () use ($keep, $other, $by): Lead {
            $this->fill($keep, $other->only(['name', 'email', 'phone', 'message', 'fields', 'utm', 'value', 'currency']));

            // Whoever already works the other lead keeps working this one.
            if ($keep->assigned_to === null && $other->assigned_to !== null) {
                $keep->assigned_to = $other->assigned_to;
                $keep->assigned_at = $other->assigned_at;
                $keep->department_id = $other->department_id ?? $keep->department_id;
            }

            $keep->department_id ??= $other->department_id;

            // The lead stands where the further of the two stood, and is as old as the older one.
            if ($other->status->sort > $keep->status->sort) {
                $keep->status_id = $other->status_id;
            }

            $keep->first_contacted_at = collect([$keep->first_contacted_at, $other->first_contacted_at])->filter()->min();
            $keep->created_at = collect([$keep->created_at, $other->created_at])->filter()->min();
            $keep->save();

            foreach (self::CHILDREN as $table) {
                DB::table($table)->where('lead_id', $other->getKey())->update(['lead_id' => $keep->getKey()]);
            }

            // The other lead's history is this lead's history now. Nothing on a timeline is ever deleted.
            DB::table((string) config('activitylog.table_name', 'activity_log'))
                ->where('subject_type', $other->getMorphClass())
                ->where('subject_id', $other->getKey())
                ->update(['subject_id' => $keep->getKey()]);

            $keep->forceFill(['next_follow_up_at' => DB::table('tasks')->where('lead_id', $keep->getKey())->whereNull('done_at')->min('due_at')])->saveQuietly();

            Audit::log(
                'leads.merged',
                $keep,
                ['merged_lead_id' => $other->getKey(), 'source' => $other->source_type->value, 'message' => $other->message],
                'Merged with the '.$other->source_type->getLabel().' lead of '.$other->name.': one person, one lead',
                causer: $by,
                logName: 'leads',
            );

            $other->delete();
            $keep->touchActivity();

            return $keep;
        });
    }

    /**
     * An enquiry for a lead that is already there: its details fill what the
     * lead is missing and its arrival goes on the timeline.
     *
     * @param  array<string, mixed>  $data  as for CreateLead: name, email, phone, message, fields, utm
     */
    public function join(Lead $lead, array $data, string $event, string $description, ?User $by = null): Lead
    {
        return DB::transaction(function () use ($lead, $data, $event, $description, $by): Lead {
            $data['email'] = filled($data['email'] ?? null) ? mb_strtolower(trim((string) $data['email'])) : null;
            $data['phone'] = PhoneNormalizer::e164($data['phone'] ?? null, Brand::query()->findOrFail($lead->brand_id));

            $this->fill($lead, $data);
            $lead->save();

            Audit::log($event, $lead, ['message' => $data['message'] ?? null, 'fields' => $data['fields'] ?? []], $description, causer: $by, logName: 'leads');

            $lead->touchActivity();

            return $lead;
        });
    }

    /**
     * What the lead does not have yet is taken from `$incoming`; what it has stays.
     *
     * @param  array<string, mixed>  $incoming
     */
    private function fill(Lead $lead, array $incoming): void
    {
        $nameless = LeadMatcher::isPlaceholder($lead->name) || $lead->name === $lead->email || $lead->name === $lead->phone;

        if ($nameless && filled($incoming['name'] ?? null) && ! LeadMatcher::isPlaceholder((string) $incoming['name'])) {
            $lead->name = mb_substr(trim((string) $incoming['name']), 0, 160);
        }

        foreach (['email', 'phone', 'message', 'value', 'currency'] as $key) {
            if (blank($lead->{$key}) && filled($incoming[$key] ?? null)) {
                $lead->{$key} = $incoming[$key];
            }
        }

        $lead->fields = [...(array) ($incoming['fields'] ?? []), ...(array) $lead->fields] ?: null;
        $lead->utm = [...(array) ($incoming['utm'] ?? []), ...(array) $lead->utm] ?: null;

        if ($lead->client_id === null) {
            $lead->client_id = Client::match((int) $lead->brand_id, $lead->email, $lead->phone)?->getKey();
        }
    }
}
