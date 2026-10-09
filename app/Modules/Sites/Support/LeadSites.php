<?php

declare(strict_types=1);

namespace App\Modules\Sites\Support;

use App\Modules\Leads\Enums\LeadSource;
use App\Modules\Leads\Models\Lead;
use App\Modules\Sites\Models\Site;

/**
 * Puts the website a lead came from on the lead as its `site` relation, so
 * lists can show the website's favicon and name as the source. One query for
 * the whole list. `leads.source_id` is a website only for Website leads (a
 * Facebook lead keeps its Page there), so this is not an Eloquent relation.
 */
final class LeadSites
{
    /** @param  iterable<Lead>  $leads */
    public static function attach(iterable $leads): void
    {
        $ids = [];

        foreach ($leads as $lead) {
            if (self::fromWebsite($lead)) {
                $ids[] = (int) $lead->source_id;
            }
        }

        $sites = $ids === []
            ? collect()
            : Site::query()->whereKey(array_unique($ids))->get(['id', 'brand_id', 'name', 'url', 'favicon_path'])->keyBy('id');

        foreach ($leads as $lead) {
            $lead->setRelation('site', self::fromWebsite($lead) ? $sites->get((int) $lead->source_id) : null);
        }
    }

    private static function fromWebsite(Lead $lead): bool
    {
        return $lead->source_type === LeadSource::Website && $lead->source_id !== null;
    }
}
