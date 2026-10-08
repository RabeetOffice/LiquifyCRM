<?php

declare(strict_types=1);

namespace App\Modules\Meta\Http\Controllers;

use App\Modules\Meta\Actions\ConnectFacebook;
use App\Modules\Meta\Exceptions\MetaException;
use App\Modules\Meta\Support\MetaApp;
use App\Modules\Meta\Support\MetaGraph;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * "Connect Facebook": one connection for all brands, made by a Super Admin
 * or Admin. /connect starts Facebook Login for Business; /callback saves the
 * token, lists the Pages and subscribes them to the webhook.
 */
final class FacebookConnectController
{
    public function connect(Request $request): RedirectResponse
    {
        if (! MetaApp::isConfigured()) {
            return $this->back('Add META_APP_ID and META_APP_SECRET to the .env file first.', 'danger');
        }

        $state = Str::random(40);
        $request->session()->put('meta_oauth_state', $state);

        return redirect()->away(MetaApp::loginUrl($state));
    }

    public function callback(Request $request, MetaGraph $graph, ConnectFacebook $connect): RedirectResponse
    {
        $expected = (string) $request->session()->pull('meta_oauth_state', '');

        if ($expected === '' || ! hash_equals($expected, (string) $request->query('state', ''))) {
            return $this->back('The Facebook sign-in could not be verified. Please try again.', 'danger');
        }

        if ($request->query('error') !== null || blank($request->query('code'))) {
            return $this->back('Facebook was not connected: '.($request->query('error_description') ?? 'the sign-in was cancelled').'.', 'warning');
        }

        try {
            $token = $graph->tokenFromCode((string) $request->query('code'));
            $connection = $connect->handle($token['token'], $token['expires_in'], $request->user());
        } catch (MetaException $exception) {
            return $this->back('Facebook was not connected: '.$exception->getMessage(), 'danger');
        }

        $pages = $connection->sources()->count();

        return $this->back('Facebook connected as '.$connection->fb_user_name.'. '.$pages.' '.str('Page')->plural($pages).' found; map each one to its brand below.', 'success');
    }

    private function back(string $message, string $type): RedirectResponse
    {
        return redirect()->route('admin.integrations')->with('integration_notice', ['message' => $message, 'type' => $type]);
    }
}
