<?php

declare(strict_types=1);

namespace App\Modules\Meta\Actions;

use App\Modules\Auth\Models\User;
use App\Modules\Core\Support\Audit;
use App\Modules\Meta\Exceptions\MetaException;
use App\Modules\Meta\Models\MetaConnection;
use App\Modules\Meta\Models\MetaSource;
use App\Modules\Meta\Support\MetaGraph;

/**
 * Save the one Facebook connection, bring in every Page it manages and
 * subscribe each Page to leads and messages. Pages keep the brand they are
 * already mapped to.
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

    /** @return int number of Pages found */
    public function syncPages(MetaConnection $connection): int
    {
        $pages = $this->graph->pages($connection->user_token);

        foreach ($pages as $page) {
            $source = MetaSource::query()->updateOrCreate(['external_id' => $page['id']], [
                'meta_connection_id' => $connection->getKey(),
                'type' => MetaSource::TYPE_PAGE,
                'name' => $page['name'],
                'access_token' => $page['access_token'],
            ])->refresh();

            try {
                $this->graph->subscribe($source);
                $source->update(['subscribed_fields' => MetaSource::FIELDS, 'subscribed_at' => now(), 'error' => null]);
            } catch (MetaException $exception) {
                $source->update(['error' => mb_substr($exception->getMessage(), 0, 500)]);
            }
        }

        return count($pages);
    }
}
