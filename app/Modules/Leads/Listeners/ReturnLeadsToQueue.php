<?php

declare(strict_types=1);

namespace App\Modules\Leads\Listeners;

use App\Modules\Brands\Events\MemberRemoved;
use App\Modules\Core\Enums\BrandRole;
use App\Modules\Core\Support\Audit;
use App\Modules\Leads\Models\Lead;

/**
 * Removing someone sends their open leads back to the department queue, or
 * to the Admin's queue when a Manager leaves.
 */
final class ReturnLeadsToQueue
{
    public function handle(MemberRemoved $event): void
    {
        $query = Lead::query()->forBrand($event->brand)->open()->where('assigned_to', $event->user->getKey());
        $count = $query->count();

        if ($count === 0) {
            return;
        }

        $changes = ['assigned_to' => null, 'assigned_at' => null];

        if ($event->role === BrandRole::Manager) {
            $changes['department_id'] = null;
        }

        $query->update($changes);

        Audit::log(
            'leads.returned_to_queue',
            null,
            ['user_id' => $event->user->getKey(), 'count' => $count],
            "{$count} open ".str('lead')->plural($count)." of {$event->user->name} returned to the queue",
            brand: $event->brand,
            causer: $event->by,
            logName: 'leads',
        );
    }
}
