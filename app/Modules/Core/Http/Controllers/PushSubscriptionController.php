<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Controllers;

use App\Modules\Auth\Models\User;
use App\Modules\Core\Http\Requests\PushSubscriptionRequest;
use App\Modules\Core\Models\PushSubscription;
use App\Modules\Core\Support\WebPush;
use Illuminate\Http\Response;

/**
 * This browser starts or stops getting desktop alerts with LiquifyCRM closed.
 * The page sends its subscription when desktop alerts are on (every time it
 * opens, so a browser follows whoever signed in last) and takes it back when
 * they are turned off. Signing out forgets it too (CoreServiceProvider).
 */
final class PushSubscriptionController
{
    public function store(PushSubscriptionRequest $request): Response
    {
        abort_unless(WebPush::ready(), 404);

        /** @var User $user */
        $user = $request->user();
        $endpoint = (string) $request->validated('endpoint');
        $hash = PushSubscription::hashOf($endpoint);

        PushSubscription::query()->updateOrCreate(['endpoint_hash' => $hash], [
            'user_id' => $user->getKey(),
            'endpoint' => $endpoint,
            'public_key' => (string) $request->validated('keys.p256dh'),
            'auth_token' => (string) $request->validated('keys.auth'),
            'content_encoding' => (string) ($request->validated('contentEncoding') ?? 'aes128gcm'),
        ])->touch();

        // A person keeps a handful of browsers; the one unused longest makes room.
        PushSubscription::query()
            ->where('user_id', $user->getKey())
            ->orderByDesc('updated_at')
            ->skip(PushSubscription::PER_PERSON)
            ->take(100)
            ->get()
            ->each->delete();

        $request->session()->put('push_endpoint', $hash);

        return response()->noContent();
    }

    public function destroy(PushSubscriptionRequest $request): Response
    {
        abort_unless(WebPush::ready(), 404);

        PushSubscription::query()
            ->where('user_id', $request->user()?->getKey())
            ->where('endpoint_hash', PushSubscription::hashOf((string) $request->validated('endpoint')))
            ->delete();

        $request->session()->forget('push_endpoint');

        return response()->noContent();
    }
}
