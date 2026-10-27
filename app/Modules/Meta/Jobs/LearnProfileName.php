<?php

declare(strict_types=1);

namespace App\Modules\Meta\Jobs;

use App\Modules\Core\Support\RunSoon;
use App\Modules\Leads\Enums\LeadSource;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Support\LeadMatcher;
use App\Modules\Leads\Support\ProfileNames;
use App\Modules\Meta\Models\Conversation;
use App\Modules\Meta\Models\MetaSource;
use App\Modules\Meta\Support\MetaApp;
use App\Modules\Meta\Support\MetaGraph;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The name on the profile of the person behind a Facebook or Instagram lead
 * (user report, 27 Oct 2026): from the lead's chat, or from the chat its lead
 * form names (inbox_url). Messenger and Instagram search by that name, not by
 * what was typed into a form. Asked when a lead form arrives, when a lead
 * without one is opened, and for open leads by `meta:profile-names`; at most
 * once in twelve hours per lead. Nothing learned is not an error.
 */
final class LearnProfileName implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public readonly int $leadId) {}

    /** Ask for this lead right after the answer, unless it has its name or was asked lately. */
    public static function soon(Lead $lead): void
    {
        if (self::due($lead)) {
            RunSoon::dispatch(new self($lead->getKey()));
        }
    }

    /** Should Meta be asked for this lead now? Says yes once in twelve hours per lead. */
    public static function due(Lead $lead): bool
    {
        return ProfileNames::ready()
            && MetaApp::isConfigured()
            && blank($lead->getAttribute('profile_name'))
            && in_array($lead->source_type, [LeadSource::Facebook, LeadSource::Messenger, LeadSource::Instagram], true)
            && Cache::add('lead-profile-name:'.$lead->getKey(), true, now()->addHours(12));
    }

    public function handle(MetaGraph $graph): void
    {
        if (! ProfileNames::ready()) {
            return;
        }

        $lead = Lead::query()->acrossBrands()->find($this->leadId);

        if ($lead === null) {
            return;
        }

        try {
            $name = $this->ask($lead, $graph);
        } catch (Throwable $exception) {
            // Meta out of reach: the next ask tries again.
            report($exception);

            return;
        }

        if ($name !== null && $name !== $lead->getAttribute('profile_name')) {
            $lead->forceFill(['profile_name' => $name])->save();
        }
    }

    /** The name Meta gives for the person: their chat with the Page, or the chat their lead form names. */
    private function ask(Lead $lead, MetaGraph $graph): ?string
    {
        $chat = Conversation::query()->acrossBrands()->with('source')->where('lead_id', $lead->getKey())->latest('id')->first();

        if ($chat?->source !== null) {
            if ($chat->isInstagram()) {
                $profile = $graph->instagramProfile($chat->source, $chat->contact_external_id);

                return $profile['name'] ?? ($profile['username'] !== null ? '@'.$profile['username'] : null);
            }

            return $graph->contactProfile($chat->source, $chat->contact_external_id, withPicture: false)['name'];
        }

        $psid = LeadMatcher::messengerId($lead);
        $page = $lead->source_type === LeadSource::Facebook && $lead->source_id !== null ? MetaSource::query()->find($lead->source_id) : null;

        return $psid !== null && $page !== null ? $graph->contactProfile($page, $psid, withPicture: false)['name'] : null;
    }
}
