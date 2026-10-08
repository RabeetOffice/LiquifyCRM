<?php

declare(strict_types=1);

namespace App\Modules\Sites\Http\Controllers;

use App\Modules\Sites\Models\Site;
use App\Modules\Sites\Support\IngestSignature;
use App\Modules\Sites\Support\SiteIngestor;
use App\Modules\Sites\Support\WebhookPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/v1/ingest/site/{site_key}: the one entry point for website leads.
 * It only verifies the request, keeps the raw copy and answers 202; the
 * IngestLead job does everything else from the queue.
 *
 * liquify.php signs each lead (X-Liquify-Timestamp + X-Liquify-Signature).
 * Form builders that cannot sign (Elementor's Webhook action) use the
 * address with ?token=... instead.
 */
final class IngestSiteController
{
    public function __invoke(Request $request, string $siteKey, SiteIngestor $ingestor): JsonResponse
    {
        $site = Site::query()->acrossBrands()->with('brand')->where('site_key', $siteKey)->first();

        if ($site === null || ! $site->is_active || ! $site->brand->is_active) {
            return response()->json(['message' => 'Unknown website.'], 404);
        }

        $signature = (string) $request->header('X-Liquify-Signature', '');

        if ($signature !== '') {
            $timestamp = (string) $request->header('X-Liquify-Timestamp', '');
            $body = (string) $request->getContent();

            if (! IngestSignature::isFresh($timestamp) || ! IngestSignature::matches($site->secret, $timestamp, $body, $signature)) {
                return response()->json(['message' => 'Signature or timestamp not accepted.'], 401);
            }

            $payload = json_decode($body, true);

            if (! is_array($payload)) {
                return response()->json(['message' => 'The body must be JSON.'], 422);
            }
        } else {
            $token = (string) $request->query('token', '');

            if ($token === '' || ! hash_equals($site->webhookToken(), $token)) {
                return response()->json(['message' => 'Signature or timestamp not accepted.'], 401);
            }

            $payload = WebhookPayload::fromRequest($request->except('token'));

            if ($payload['fields'] === []) {
                return response()->json(['message' => 'No form fields were sent.'], 422);
            }
        }

        $event = $ingestor->accept($site, $payload);

        return response()->json([
            'status' => 'accepted',
            'duplicate' => ! $event->wasRecentlyCreated,
        ], 202);
    }
}
