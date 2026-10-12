<?php

declare(strict_types=1);

namespace App\Modules\Meta\Support;

use App\Modules\Meta\Exceptions\MetaException;
use App\Modules\Meta\Models\MetaSource;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * The Graph API calls LiquifyCRM needs, over the Laravel HTTP client (no
 * SDK). Tokens travel in the Authorization header, never in the address.
 */
final class MetaGraph
{
    /**
     * Swap the code from the Login dialog for a token that lasts about 60 days.
     *
     * @return array{token: string, expires_in: ?int}
     */
    public function tokenFromCode(string $code): array
    {
        $short = $this->get('/oauth/access_token', null, [
            'client_id' => MetaApp::id(),
            'client_secret' => MetaApp::secret(),
            'redirect_uri' => MetaApp::redirectUri(),
            'code' => $code,
        ]);

        return $this->longLivedToken((string) ($short['access_token'] ?? ''));
    }

    /**
     * @return array{token: string, expires_in: ?int}
     */
    public function longLivedToken(string $token): array
    {
        $long = $this->get('/oauth/access_token', null, [
            'grant_type' => 'fb_exchange_token',
            'client_id' => MetaApp::id(),
            'client_secret' => MetaApp::secret(),
            'fb_exchange_token' => $token,
        ]);

        if (blank($long['access_token'] ?? null)) {
            throw new MetaException('Facebook did not return a token.');
        }

        return ['token' => (string) $long['access_token'], 'expires_in' => isset($long['expires_in']) ? (int) $long['expires_in'] : null];
    }

    /** @return array{id: string, name: string} */
    public function me(string $token): array
    {
        $me = $this->get('/me', $token, ['fields' => 'id,name']);

        return ['id' => (string) ($me['id'] ?? ''), 'name' => (string) ($me['name'] ?? '')];
    }

    /**
     * Every Page the token can manage, with its own Page token.
     *
     * @return list<array{id: string, name: string, access_token: string}>
     */
    public function pages(string $token): array
    {
        $pages = [];
        $after = null;

        for ($page = 0; $page < 20; $page++) {
            $response = $this->get('/me/accounts', $token, array_filter(['fields' => 'id,name,access_token', 'limit' => 100, 'after' => $after]));

            foreach ((array) ($response['data'] ?? []) as $row) {
                if (filled($row['id'] ?? null) && filled($row['access_token'] ?? null)) {
                    $pages[] = ['id' => (string) $row['id'], 'name' => (string) ($row['name'] ?? $row['id']), 'access_token' => (string) $row['access_token']];
                }
            }

            $after = $response['paging']['cursors']['after'] ?? null;

            if (blank($after) || blank($response['paging']['next'] ?? null)) {
                break;
            }
        }

        return $pages;
    }

    /** Ask Meta to send this Page's leads and messages to the webhook. */
    public function subscribe(MetaSource $page): void
    {
        $this->post('/'.$page->external_id.'/subscribed_apps', $this->pageToken($page), ['subscribed_fields' => implode(',', MetaSource::FIELDS)]);
    }

    public function unsubscribe(MetaSource $page): void
    {
        $this->send('delete', '/'.$page->external_id.'/subscribed_apps', $this->pageToken($page));
    }

    /**
     * One Lead Ads lead: the webhook only carries its id.
     *
     * @return array<string, mixed>
     */
    public function lead(MetaSource $page, string $leadgenId): array
    {
        return $this->get('/'.$leadgenId, $this->pageToken($page), ['fields' => 'id,created_time,field_data,form_id,ad_id,ad_name,campaign_name,platform']);
    }

    /** The name of a lead form, for the lead's extra fields. Never fails the lead. */
    public function formName(MetaSource $page, string $formId): ?string
    {
        try {
            return (string) ($this->get('/'.$formId, $this->pageToken($page), ['fields' => 'name'])['name'] ?? '') ?: null;
        } catch (MetaException) {
            return null;
        }
    }

    /**
     * The Messenger contact's name and picture. Meta only shares them for
     * people it lets the app see (everyone once the app has passed App Review,
     * before that only people with a role on the app), so both may be null.
     * It never shares a link to the person's profile.
     *
     * @return array{name: ?string, picture: ?string}
     */
    public function contactProfile(MetaSource $page, string $psid): array
    {
        try {
            $profile = $this->get('/'.$psid, $this->pageToken($page), ['fields' => 'first_name,last_name,profile_pic']);
        } catch (MetaException) {
            return ['name' => null, 'picture' => null];
        }

        $picture = (string) ($profile['profile_pic'] ?? '');

        return [
            'name' => trim(($profile['first_name'] ?? '').' '.($profile['last_name'] ?? '')) ?: null,
            'picture' => str_starts_with($picture, 'https://') ? $picture : null,
        ];
    }

    /** Send a Messenger reply inside the 24-hour window. Returns Meta's message id. */
    public function sendMessage(MetaSource $page, string $psid, string $text): string
    {
        $response = $this->post('/'.$page->external_id.'/messages', $this->pageToken($page), [
            'recipient' => ['id' => $psid],
            'messaging_type' => 'RESPONSE',
            'message' => ['text' => $text],
        ]);

        return (string) ($response['message_id'] ?? '');
    }

    private function pageToken(MetaSource $page): string
    {
        return $page->access_token ?? throw new MetaException('This Page has no token. Click "Refresh Pages" on the Integrations screen.');
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function get(string $path, ?string $token, array $query = []): array
    {
        return $this->send('get', $path, $token, $query);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function post(string $path, ?string $token, array $data = []): array
    {
        return $this->send('post', $path, $token, $data);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function send(string $method, string $path, ?string $token, array $data = []): array
    {
        $request = Http::baseUrl('https://graph.facebook.com/'.MetaApp::version())
            ->timeout(20)
            ->connectTimeout(8)
            ->acceptJson();

        if ($token !== null) {
            $request = $request->withToken($token);
        }

        try {
            /** @var Response $response */
            $response = $request->{$method}($path, $data);
        } catch (ConnectionException $exception) {
            throw new MetaException('Facebook could not be reached: '.$exception->getMessage());
        }

        $json = (array) $response->json();

        if ($response->failed() || isset($json['error'])) {
            $error = (array) ($json['error'] ?? []);

            throw new MetaException(
                (string) ($error['message'] ?? 'Facebook answered with error '.$response->status().'.'),
                (int) ($error['code'] ?? 0),
                isset($error['error_subcode']) ? (int) $error['error_subcode'] : null,
            );
        }

        return $json;
    }
}
