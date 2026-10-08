<?php

declare(strict_types=1);

namespace App\Modules\Leads\Http\Controllers;

use App\Modules\Brands\Models\Brand;
use App\Modules\Leads\Actions\RevealLeadContact;
use App\Modules\Leads\Exceptions\RevealLimitReached;
use App\Modules\Leads\Models\Lead;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** POST /b/{brand}/leads/{lead}/reveal: show the masked contact details (logged, limited per hour). */
final class RevealContactController
{
    public function __invoke(Request $request, Brand $brand, Lead $lead, RevealLeadContact $reveal): JsonResponse
    {
        Gate::authorize('reveal', $lead);

        try {
            $reveal->handle($lead->loadMissing('brand'), $request->user());
        } catch (RevealLimitReached $exception) {
            return response()->json(['message' => $exception->getMessage()], 429);
        }

        return response()->json(['email' => $lead->email, 'phone' => $lead->phone]);
    }
}
