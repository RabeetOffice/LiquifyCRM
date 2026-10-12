<?php

declare(strict_types=1);

namespace App\Modules\Meta\Actions;

use App\Modules\Meta\Models\Conversation;
use App\Modules\Meta\Support\MetaGraph;
use Illuminate\Support\Facades\Schema;

/**
 * The name and picture of the person in a Messenger chat, from Facebook.
 * Asked on the first message, again once a day (the picture's address
 * expires), and whenever someone clicks "Refresh from Facebook". A name the
 * team typed in by hand is never replaced; a lead still called "Messenger
 * contact" takes the real name once Facebook shares it.
 */
final class SyncContactProfile
{
    public function __construct(private readonly MetaGraph $graph) {}

    /**
     * @return array{name: bool, picture: bool, reason: ?string} what was learned, or Facebook's reason for nothing
     */
    public function handle(Conversation $conversation, bool $force = false): array
    {
        $nothing = ['name' => false, 'picture' => false, 'reason' => null];
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

        $profile = $this->graph->contactProfile($page, $conversation->contact_external_id);
        $named = $conversation->contact_name === null && $profile['name'] !== null;

        $conversation->forceFill(array_filter([
            'contact_name' => $named ? $profile['name'] : null,
            'contact_picture' => $ready ? $profile['picture'] : null,
            'contact_synced_at' => $ready ? now() : null,
        ], fn (mixed $value): bool => $value !== null))->save();

        $lead = $conversation->lead;

        if ($named && $lead !== null && $lead->name === 'Messenger contact') {
            $lead->forceFill(['name' => $profile['name']])->save();
        }

        return ['name' => $named, 'picture' => $ready && $profile['picture'] !== null, 'reason' => $profile['reason']];
    }
}
