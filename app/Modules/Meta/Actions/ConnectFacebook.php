<?php

declare(strict_types=1);

namespace App\Modules\Meta\Actions;

use App\Modules\Auth\Models\User;
use App\Modules\Core\Support\Audit;
use App\Modules\Core\Support\RunSoon;
use App\Modules\Meta\Exceptions\MetaException;
use App\Modules\Meta\Jobs\ProcessMetaEvent;
use App\Modules\Meta\Jobs\SubscribePages;
use App\Modules\Meta\Models\MetaConnection;
use App\Modules\Meta\Models\MetaSource;
use App\Modules\Meta\Support\Instagram;
use App\Modules\Meta\Support\MetaGraph;
use App\Modules\Sites\Enums\InboundStatus;
use App\Modules\Sites\Models\InboundEvent;

/**
 * Save the one Facebook connection, bring in every Page it manages (with the
 * Instagram account linked to it) and subscribe each Page to leads and
 * messages. Pages keep the brand they are already mapped to.
 */
final class ConnectFacebook
{
    public function __construct(private readonly MetaGraph $graph) {}

    public function handle(string $token, ?int $expiresIn, ?User $by, bool $systemUser = false): MetaConnection
    {
        $me = $this->graph->me($token);

        if ($me['id'] === '') {
            throw new MetaException('Facebook did not accept this token.');
        }

        $connection = MetaConnection::query()->updateOrCreate(['fb_user_id' => $me['id']], [
            'fb_user_name' => $me['name'],
            'user_token' => $token,
            'is_system_user' => $systemUser,
            'expires_at' => $expiresIn !== null && $expiresIn > 0 ? now()->addSeconds($expiresIn) : null,
            'last_refreshed_at' => now(),
            'error' => null,
            'connected_by' => $by?->getKey(),
        ]);

        $pages = $this->syncPages($connection);

        Audit::log('integrations.facebook_connected', $connection, ['name' => $me['name'], 'pages' => $pages, 'system_user' => $systemUser], 'Facebook connected as '.$me['name'], causer: $by, logName: 'integrations');

        return $connection;
    }

    /**
     * One call to Meta for the list (Pages, tokens, Instagram accounts); the
     * Pages are subscribed to the webhook right after the answer has gone out
     * (one call each), so connecting a Facebook account with dozens of Pages
     * does not keep the screen waiting until the host gives up with a 503.
     * Pages already subscribed are left alone unless `$force` (Refresh Pages).
     *
     * @return int number of Pages found
     */
    public function syncPages(MetaConnection $connection, bool $force = false): int
    {
        $pages = $this->graph->pages($connection->user_token);
        $ids = [];

        foreach ($pages as $page) {
            $source = MetaSource::query()->updateOrCreate(['external_id' => $page['id']], [
                'meta_connection_id' => $connection->getKey(),
                'type' => MetaSource::TYPE_PAGE,
                'name' => $page['name'],
                'access_token' => $page['access_token'],
            ])->refresh();

            if (array_key_exists('instagram', $page)) {
                $this->linkInstagram($source, $page['instagram']);
            }

            $ids[] = (int) $source->getKey();
        }

        if ($ids !== []) {
            RunSoon::dispatch(new SubscribePages($ids, $force));
        }

        return count($pages);
    }

    /**
     * The Instagram account linked to the Page, whose direct messages arrive
     * under the account's own id. An account moved to another Page leaves the
     * old one. Instagram events that waited for the link are processed now.
     *
     * @param  array{id: string, username: ?string}|null  $account
     */
    private function linkInstagram(MetaSource $source, ?array $account): void
    {
        if (! Instagram::ready()) {
            return;
        }

        $id = $account['id'] ?? null;

        if ($id !== null) {
            MetaSource::query()->where('instagram_id', $id)->whereKeyNot($source->getKey())->update(['instagram_id' => null, 'instagram_username' => null]);
        }

        $source->forceFill(['instagram_id' => $id, 'instagram_username' => $account['username'] ?? null])->save();

        if ($id !== null && $source->brand_id !== null) {
            InboundEvent::query()
                ->where('source', ProcessMetaEvent::INSTAGRAM)
                ->where('status', InboundStatus::Failed->value)
                ->where('payload->instagram_id', $id)
                ->limit(500)
                ->get()
                ->each->process();
        }
    }
}
