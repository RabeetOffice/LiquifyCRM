<?php

declare(strict_types=1);

namespace App\Modules\Meta\Actions;

use App\Modules\Leads\Support\LeadMatcher;
use App\Modules\Leads\Support\ProfileNames;
use App\Modules\Meta\Models\Conversation;
use App\Modules\Meta\Support\Instagram;
use App\Modules\Meta\Support\MetaGraph;
use Illuminate\Support\Facades\Schema;

/**
 * The name and picture of the person in a Messenger or Instagram chat (and
 * on Instagram the username), from Meta. Asked on the first message, again
 * once a day (the picture's address expires), and whenever someone clicks
 * the refresh button. A name the team typed in by hand is never replaced; a
 * lead still called "Messenger contact", "Instagram contact" or only by the
 * username takes the real name once Meta shares it, and every lead keeps
 * the profile name beside its own (ProfileNames).
 */
final class SyncContactProfile
{
    public function __construct(private readonly MetaGraph $graph) {}

    /**
     * What was learned, or Meta's reason for nothing; `profileName` is the
     * name Meta gave, for a lead the chat only gets afterwards.
     *
     * @return array{name: bool, picture: bool, reason: ?string, profileName: ?string}
     */
    public function handle(Conversation $conversation, bool $force = false): array
    {
        $nothing = ['name' => false, 'picture' => false, 'reason' => null, 'profileName' => null];
        $conversation->loadMissing(['source', 'lead']);
        $page = $conversation->source;

        if ($page === null) {
            return [...$nothing, 'reason' => 'The Facebook Page of this chat is no longer connected.'];
        }

        // Until "Update system" has added the two columns, only the name is kept, as before.
        static $ready = null;
        $ready ??= Schema::hasColumn('conversations', 'contact_picture');

        if (! $force) {
            $synced = $ready ? $conversation->contact_synced_at : null;

            if (($synced !== null && $synced->gt(now()->subDay())) || (! $ready && $conversation->contact_name !== null)) {
                return $nothing;
            }
        }

        $before = $conversation->displayName();
        $handle = null;

        if ($conversation->isInstagram()) {
            $profile = $this->graph->instagramProfile($page, $conversation->contact_external_id);
            $handle = Instagram::ready() ? $profile['username'] : null;
        } else {
            $profile = $this->graph->contactProfile($page, $conversation->contact_external_id);
        }

        $named = $conversation->contact_name === null && $profile['name'] !== null;

        $conversation->forceFill(array_filter([
            'contact_name' => $named ? $profile['name'] : null,
            'contact_handle' => $handle,
            'contact_picture' => $ready ? $profile['picture'] : null,
            'contact_synced_at' => $ready ? now() : null,
        ], fn (mixed $value): bool => $value !== null))->save();

        $lead = $conversation->lead;
        $after = $conversation->displayName();
        $changes = [];

        // A lead that still carries the stand-in name ("Instagram contact", or only the @username) takes the better one.
        if ($after !== $before && $lead !== null && ($lead->name === $before || LeadMatcher::isPlaceholder($lead->name))) {
            $changes['name'] = $after;
        }

        // The name Messenger and Instagram search by stays beside the one the lead goes by (ProfileNames).
        $profileName = $profile['name'] ?? ($handle !== null ? '@'.$handle : null);

        if ($lead !== null && $profileName !== null && ProfileNames::ready() && $lead->getAttribute('profile_name') !== $profileName) {
            $changes['profile_name'] = $profileName;
        }

        if ($lead !== null && $changes !== []) {
            $lead->forceFill($changes)->save();
        }

        return ['name' => $named || ($handle !== null && $after !== $before), 'picture' => $ready && $profile['picture'] !== null, 'reason' => $profile['reason'], 'profileName' => $profileName];
    }
}
