<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Controllers;

use App\Modules\Core\Support\Live;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Has anything changed?" Every open screen asks this every few seconds
 * (see Live and app.js). It is kept as small as a request can be: no
 * session, no sign-in query, one cache read.
 */
final class LiveController
{
    public function __invoke(Request $request): JsonResponse
    {
        $stamps = Live::read((string) $request->query('t', ''));

        if ($stamps === null) {
            return response()->json(['message' => 'Reload the page.'], 403);
        }

        return response()->json($stamps)->header('Cache-Control', 'no-store');
    }
}
