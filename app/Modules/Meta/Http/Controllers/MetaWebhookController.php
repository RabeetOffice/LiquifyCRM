<?php

declare(strict_types=1);

namespace App\Modules\Meta\Http\Controllers;

use App\Modules\Meta\Jobs\ProcessMetaEvent;
use App\Modules\Meta\Jobs\UpdateMessageReceipts;
use App\Modules\Meta\Support\MetaApp;
use App\Modules\Sites\Models\InboundEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * GET /webhooks/meta answers Meta's one-time verification with the verify
 * token. POST /webhooks/meta receives Lead Ads and Messenger events: it only
 * checks X-Hub-Signature-256, stores each event and answers 200 at once; the
 * queue does the rest.
 */
final class MetaWebhookController
{
    public function verify(Request $request): Response
    {
        $token = (string) $request->query('hub_verify_token', '');

        if ($request->query('hub_mode') !== 'subscribe' || MetaApp::verifyToken() === '' || ! hash_equals(MetaApp::verifyToken(), $token)) {
            return response('Forbidden', 403);
        }

        return response((string) $request->query('hub_challenge', ''), 200, ['Content-Type' => 'text/plain']);
    }

    public function receive(Request $request): Response
    {
        $body = (string) $request->getContent();

        if (! MetaApp::signatureMatches($body, (string) $request->header('X-Hub-Signature-256', ''))) {
            return response('Signature not accepted', 401);
        }

        $payload = json_decode($body, true);

        if (! is_array($payload) || ($payload['object'] ?? null) !== 'page') {
            // Other objects (Instagram, WhatsApp) arrive later; tell Meta they were received.
            return response('EVENT_RECEIVED', 200);
        }

        foreach ((array) ($payload['entry'] ?? []) as $entry) {
            $pageId = (string) ($entry['id'] ?? '');

            foreach ((array) ($entry['changes'] ?? []) as $change) {
                $value = (array) ($change['value'] ?? []);

                if (($change['field'] ?? null) === 'leadgen' && filled($value['leadgen_id'] ?? null)) {
                    $this->store(ProcessMetaEvent::LEADGEN, (string) $value['leadgen_id'], ['page_id' => (string) ($value['page_id'] ?? $pageId), ...$value]);
                }
            }

            foreach ((array) ($entry['messaging'] ?? []) as $item) {
                $psid = (string) ($item['sender']['id'] ?? '');

                if (isset($item['message']) || isset($item['postback'])) {
                    $mid = (string) ($item['message']['mid'] ?? $item['postback']['mid'] ?? '');
                    $this->store(ProcessMetaEvent::MESSAGE, $mid !== '' ? $mid : null, ['page_id' => $pageId, ...$item]);
                } elseif (isset($item['delivery']['watermark'])) {
                    UpdateMessageReceipts::dispatch($pageId, $psid, 'delivered', (int) $item['delivery']['watermark']);
                } elseif (isset($item['read']['watermark'])) {
                    UpdateMessageReceipts::dispatch($pageId, $psid, 'read', (int) $item['read']['watermark']);
                }
            }
        }

        return response('EVENT_RECEIVED', 200);
    }

    /**
     * Meta repeats an event until it gets a 200, so the same id is stored once.
     *
     * @param  array<string, mixed>  $payload
     */
    private function store(string $source, ?string $externalId, array $payload): void
    {
        $externalId = $externalId !== null ? mb_substr($externalId, 0, 120) : null;

        if ($externalId !== null && InboundEvent::query()->where('source', $source)->where('external_id', $externalId)->exists()) {
            return;
        }

        try {
            $event = InboundEvent::query()->create(['source' => $source, 'external_id' => $externalId, 'payload' => $payload]);
        } catch (UniqueConstraintViolationException) {
            return;
        }

        ProcessMetaEvent::dispatch((int) $event->getKey());
    }
}
