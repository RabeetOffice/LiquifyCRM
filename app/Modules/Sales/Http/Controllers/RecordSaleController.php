<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

use App\Modules\Brands\Models\Brand;
use App\Modules\Leads\Models\Lead;
use App\Modules\Sales\Actions\RecordSale;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** POST /b/{brand}/leads/{lead}/won: record a sale; creates or updates the client. */
final class RecordSaleController
{
    public function __invoke(Request $request, Brand $brand, Lead $lead, RecordSale $action): JsonResponse
    {
        Gate::authorize('recordSale', $lead);

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999'],
            'service' => ['nullable', 'string', 'max:190'],
            'closed_at' => ['nullable', 'date', 'before_or_equal:today'],
            'paid' => ['nullable', 'numeric', 'min:0', 'lte:amount'],
        ]);

        $closedAt = isset($data['closed_at']) ? CarbonImmutable::parse($data['closed_at'])->setTimeFrom(now()) : now();
        $sale = $action->handle($lead, (float) $data['amount'], $data['service'] ?? null, $closedAt, $request->user(), isset($data['paid']) ? (float) $data['paid'] : null);

        return response()->json([
            'id' => $sale->getKey(),
            'client_id' => $sale->client_id,
            'amount' => $sale->amount,
            'currency' => $sale->currency,
        ], 201);
    }
}
