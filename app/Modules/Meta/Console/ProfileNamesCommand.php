<?php

declare(strict_types=1);

namespace App\Modules\Meta\Console;

use App\Modules\Leads\Enums\LeadSource;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Support\ProfileNames;
use App\Modules\Meta\Jobs\LearnProfileName;
use App\Modules\Meta\Support\MetaApp;
use App\Modules\Meta\Support\MetaGraph;
use Illuminate\Console\Command;

/**
 * Every ten minutes: open Facebook, Messenger and Instagram leads of the last
 * two months that have no profile name yet are asked for it, newest first and
 * a few at a time (LearnProfileName), so the leads that were there before the
 * update get it too and can be found by it. A lead is asked at most once in
 * twelve hours.
 */
final class ProfileNamesCommand extends Command
{
    protected $signature = 'meta:profile-names {--limit=25 : Leads asked for in one run}';

    protected $description = 'Learn the Facebook or Instagram profile name of open leads';

    public function handle(MetaGraph $graph): int
    {
        if (! MetaApp::isConfigured() || ! ProfileNames::ready()) {
            return self::SUCCESS;
        }

        $limit = max(1, (int) $this->option('limit'));
        $asked = 0;

        $leads = Lead::query()
            ->acrossBrands()
            ->open()
            ->whereNull('profile_name')
            ->whereIn('source_type', [LeadSource::Facebook->value, LeadSource::Messenger->value, LeadSource::Instagram->value])
            ->where('created_at', '>=', now()->subDays(60))
            ->latest('id')
            ->limit($limit * 8)
            ->get();

        foreach ($leads as $lead) {
            if ($asked >= $limit) {
                break;
            }

            if (LearnProfileName::due($lead)) {
                (new LearnProfileName($lead->getKey()))->handle($graph);
                $asked++;
            }
        }

        $this->info('Asked Meta for '.$asked.' '.($asked === 1 ? 'lead' : 'leads').'.');

        return self::SUCCESS;
    }
}
