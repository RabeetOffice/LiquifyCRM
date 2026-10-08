<?php

declare(strict_types=1);

namespace App\Modules\Leads\Actions;

use App\Modules\Activity\Models\Activity;
use App\Modules\Auth\Models\User;
use App\Modules\Core\Support\Audit;
use App\Modules\Leads\Exceptions\RevealLimitReached;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Support\ContactVisibility;

/**
 * An Agent reveals one lead's phone and email. Every reveal is logged with
 * who, which lead, time and IP, and the brand's hourly limit stops bulk
 * copying. People who already see full details are logged as a view.
 */
final class RevealLeadContact
{
    public function handle(Lead $lead, User $by): Lead
    {
        $visibility = app(ContactVisibility::class);

        if ($visibility->canSeeFull($by, $lead) && ! $visibility->isRevealed($lead)) {
            Audit::log('leads.contact_viewed', $lead, [], 'Contact details viewed', causer: $by, logName: 'leads');
            $visibility->markRevealed($lead);

            return $lead;
        }

        if ($visibility->isRevealed($lead)) {
            return $lead;
        }

        $limit = $lead->brand->revealLimitPerHour();
        $recent = Activity::query()
            ->where('event', 'leads.contact_revealed')
            ->where('causer_type', $by->getMorphClass())
            ->where('causer_id', $by->getKey())
            ->where('brand_id', $lead->brand_id)
            ->where('created_at', '>=', now()->subHour())
            ->count();

        if ($limit > 0 && $recent >= $limit) {
            throw RevealLimitReached::perHour($limit);
        }

        Audit::log('leads.contact_revealed', $lead, ['reveals_this_hour' => $recent + 1, 'limit' => $limit], 'Contact details revealed', causer: $by, logName: 'leads');

        $visibility->markRevealed($lead);

        return $lead;
    }
}
