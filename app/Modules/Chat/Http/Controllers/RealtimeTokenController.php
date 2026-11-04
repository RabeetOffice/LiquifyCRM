<?php

declare(strict_types=1);

namespace App\Modules\Chat\Http\Controllers;

use App\Modules\Brands\Models\Brand;
use App\Modules\Chat\Support\Realtime;
use Illuminate\Http\JsonResponse;

/**
 * The address a signed-in page of a brand opens to hear "something changed" for that brand's chats (Realtime). Only
 * the brand's own channel, only for listening, and only for people the brand middleware lets in. 204 when instant
 * signals are not switched on or Ably does not answer: the page then polls as it always did.
 */
final class RealtimeTokenController
{
    public function __invoke(Brand $brand): JsonResponse
    {
        $url = Realtime::enabled() ? Realtime::listenUrl(Realtime::brandChannel((int) $brand->getKey())) : null;

        if ($url === null) {
            return response()->json(null, 204);
        }

        return response()->json(['url' => $url, 'refresh_in' => Realtime::refreshInSeconds()])->header('Cache-Control', 'no-store');
    }
}
