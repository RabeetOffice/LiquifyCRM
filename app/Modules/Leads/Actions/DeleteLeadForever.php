<?php

declare(strict_types=1);

namespace App\Modules\Leads\Actions;

use App\Modules\Activity\Models\Activity;
use App\Modules\Auth\Models\User;
use App\Modules\Brands\Models\Brand;
use App\Modules\Core\Support\Audit;
use App\Modules\Core\Support\DashboardCache;
use App\Modules\Leads\Models\Lead;
use App\Modules\Meta\Models\Conversation;
use App\Modules\Sales\Models\Client;
use App\Modules\Sales\Models\ClientFile;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sites\Models\InboundEvent;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * Delete a lead in the bin for good: the person's details, notes, follow-ups,
 * Messenger or Instagram chat, what arrived from the website or Facebook, the
 * bell entries about it, its timeline, and the leads that were merged into it
 * (they hold the same person's details). What stays is a sale it had, in the
 * numbers and with its client, and the files front sales added on the lead,
 * which move to that client. One line in the logs says it happened, without
 * the person's details. The bin also empties itself after KEEP_DAYS.
 */
final class DeleteLeadForever
{
    /** Days a deleted lead waits in the bin before it goes for good (leads:empty-bin). */
    public const KEEP_DAYS = 30;

    /** $by is null when the bin empties itself. */
    public function handle(Lead $lead, ?User $by = null): void
    {
        if (! $lead->trashed()) {
            throw new InvalidArgumentException('Only a lead in the bin can be deleted for good.');
        }

        $brandId = (int) $lead->brand_id;
        $leadId = (int) $lead->getKey();
        $source = $lead->source_type->value;
        $hadSale = Sale::query()->forBrand($brandId)->where('lead_id', $leadId)->exists();
        $paths = [];

        $merged = DB::transaction(function () use ($lead, $brandId, &$paths): int {
            $twinIds = Activity::query()
                ->where('subject_type', $lead->getMorphClass())
                ->where('subject_id', $lead->getKey())
                ->where('event', 'leads.merged')
                ->get()
                ->map(fn (Activity $activity): int => (int) $activity->properties?->get('merged_lead_id'))
                ->filter()
                ->all();

            $twins = $twinIds === [] ? collect() : Lead::query()->forBrand($brandId)->onlyTrashed()->whereKey($twinIds)->get();

            foreach ([$lead, ...$twins->all()] as $record) {
                $paths = [...$paths, ...$this->wipe($record, $brandId)];
            }

            return $twins->count();
        });

        // The files leave the disk only once nothing points at them any more.
        foreach ($paths as [$disk, $path]) {
            Storage::disk($disk)->delete($path);
        }

        Audit::log(
            'leads.deleted_forever',
            null,
            ['lead_id' => $leadId, 'source' => $source, 'had_sale' => $hadSale, 'merged_leads' => $merged, 'automatic' => $by === null],
            $by === null ? 'A lead was deleted for good, '.self::KEEP_DAYS.' days after it went into the bin' : 'A lead was deleted for good from the bin',
            brand: $brandId,
            causer: $by,
            logName: 'leads',
        );

        DashboardCache::flush($brandId);
    }

    /**
     * Everything of one lead, then the lead. Notes and follow-ups go with it in
     * the database; a sale only loses its link to the lead.
     *
     * @return list<array{0: string, 1: string}> files to remove from the disk
     */
    private function wipe(Lead $lead, int $brandId): array
    {
        $paths = [];
        $morph = $lead->getMorphClass();
        $client = $lead->client_id !== null ? Client::query()->forBrand($brandId)->find($lead->client_id) : null;

        foreach (ClientFile::query()->forBrand($brandId)->where('fileable_type', $morph)->where('fileable_id', $lead->getKey())->get() as $file) {
            if ($client !== null) {
                // A contract or invoice of the client stays in the client's storage.
                $file->forceFill(['fileable_type' => $client->getMorphClass(), 'fileable_id' => $client->getKey()])->save();
            } else {
                $paths[] = [$file->disk, $file->path];
                $file->delete();
            }
        }

        // The chat and its messages (the messages go with the conversation in the database).
        foreach (Conversation::query()->forBrand($brandId)->where('lead_id', $lead->getKey())->get() as $conversation) {
            $conversation->delete();
        }

        InboundEvent::query()->where('lead_id', $lead->getKey())->delete();

        // Bell entries that name the person and link to the lead.
        $slug = Brand::withTrashed()->whereKey($brandId)->value('slug');

        if (is_string($slug)) {
            DatabaseNotification::query()->where('data->url', 'like', '%/b/'.$slug.'/leads/'.$lead->getKey())->delete();
        }

        // Its timeline is the lead's own history and goes with it (LogArchiver keeps it only while the lead is there).
        Activity::query()->where('subject_type', $morph)->where('subject_id', $lead->getKey())->delete();

        $lead->forceDelete();

        return $paths;
    }
}
