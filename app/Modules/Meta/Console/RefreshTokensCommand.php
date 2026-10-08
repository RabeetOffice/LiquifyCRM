<?php

declare(strict_types=1);

namespace App\Modules\Meta\Console;

use App\Modules\Auth\Models\User;
use App\Modules\Core\Notifications\AppNotification;
use App\Modules\Meta\Actions\ConnectFacebook;
use App\Modules\Meta\Exceptions\MetaException;
use App\Modules\Meta\Models\MetaConnection;
use App\Modules\Meta\Support\MetaApp;
use App\Modules\Meta\Support\MetaGraph;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

/**
 * Daily: a Facebook login token lasts about 60 days, so it is renewed when it
 * has 15 days or less left and the Page tokens are fetched again. A System
 * User token never expires and is left alone. If Facebook refuses, the Super
 * Admins are told to connect again.
 */
final class RefreshTokensCommand extends Command
{
    protected $signature = 'meta:refresh-tokens';

    protected $description = 'Renew the Facebook connection token before it expires';

    public function handle(MetaGraph $graph, ConnectFacebook $connect): int
    {
        if (! MetaApp::isConfigured()) {
            return self::SUCCESS;
        }

        foreach (MetaConnection::query()->where('is_system_user', false)->get() as $connection) {
            if (! $connection->expiresSoon()) {
                continue;
            }

            try {
                $token = $graph->longLivedToken($connection->user_token);

                $connection->update([
                    'user_token' => $token['token'],
                    'expires_at' => $token['expires_in'] !== null && $token['expires_in'] > 0 ? now()->addSeconds($token['expires_in']) : null,
                    'last_refreshed_at' => now(),
                    'error' => null,
                ]);

                $connect->syncPages($connection);
                $this->info('Renewed the token of '.$connection->fb_user_name.'.');
            } catch (MetaException $exception) {
                $firstFailure = $connection->error === null;
                $connection->update(['error' => mb_substr($exception->getMessage(), 0, 500)]);

                if ($firstFailure) {
                    Notification::send(User::superAdmins()->get(), new AppNotification(
                        'Facebook needs to be connected again',
                        'The connection of '.$connection->fb_user_name.' could not be renewed. Leads and chats stop arriving when it expires.',
                        'warning',
                        url('/admin/integrations'),
                        mail: true,
                        actionLabel: 'Open Integrations',
                    ));
                }

                $this->warn('Could not renew '.$connection->fb_user_name.': '.$exception->getMessage());
            }
        }

        return self::SUCCESS;
    }
}
