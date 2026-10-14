<?php

declare(strict_types=1);

namespace App\Modules\Meta\Http\Controllers;

use App\Modules\Core\Support\RunSoon;
use App\Modules\Meta\Jobs\ProcessMetaEvent;
use App\Modules\Meta\Jobs\UpdateMessageReceipts;
use App\Modules\Meta\Support\MetaApp;
use App\Modules\Sites\Models\InboundEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * GET /webhooks/meta answers Meta's one-time verification with the verify
 * token. POST /webhooks/meta receives Lead Ads, Messenger and Instagram
 * events: it only checks X-Hub-Signature-256, stores each event and answers
 * 200 at once; the queue does the rest.
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

        if (is_array($payload) && ($payload['object'] ?? null) === 'instagram') {
            $this->instagram($payload);

            return response('EVENT_RECEIVED', 200);
        }

        if (! is_array($payload) || ($payload['object'] ?? null) !== 'page') {
            // Other objects (WhatsApp) arrive later; tell Meta they were received.
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
     * Instagram direct messages. The entry is the Instagram account (not the
     * Page), the sender the person's Instagram-scoped id. A message the
     * account sent itself comes back with "is_echo"; one the person deleted
     * comes again with "is_deleted", kept under its own id so it is not taken
     * for a repeat; a tap on an ad by somebody who already has a chat comes
     * as a "referral" alone. Meta's dashboard test sends the same event in the
     * "changes" shape, so both are read.
     *
     * @param  array<string, mixed>  $payload
     */
    private function instagram(array $payload): void
    {
        foreach ((array) ($payload['entry'] ?? []) as $entry) {
            $accountId = (string) ($entry['id'] ?? '');
            $items = (array) ($entry['messaging'] ?? []);

            foreach ((array) ($entry['changes'] ?? []) as $change) {
                if (in_array($change['field'] ?? null, ['messages', 'messaging_postbacks', 'messaging_seen', 'messaging_referral'], true) && is_array($change['value'] ?? null)) {
                    $items[] = $change['value'];
                }
            }

            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $contact = (string) ($item['sender']['id'] ?? '');

                if (isset($item['message']) || isset($item['postback'])) {
                    $mid = (string) ($item['message']['mid'] ?? $item['postback']['mid'] ?? '');
                    $deleted = ! empty($item['message']['is_deleted']);
                    $this->store(ProcessMetaEvent::INSTAGRAM, $mid !== '' ? $mid.($deleted ? ':deleted' : '') : null, ['instagram_id' => $accountId, ...$item]);
                } elseif (isset($item['referral']['ad_id']) && $contact !== '') {
                    // Somebody who already has a chat tapped an ad again: it carries no message, only the ad.
                    $this->store(ProcessMetaEvent::INSTAGRAM, 'referral:'.$contact.':'.(string) ($item['timestamp'] ?? ''), ['instagram_id' => $accountId, ...$item]);
                } elseif (isset($item['read']) && isset($item['timestamp'])) {
                    // Instagram names the message that was seen, not a watermark: everything sent until then has been seen.
                    UpdateMessageReceipts::dispatch($accountId, $contact, 'read', (int) $item['timestamp'], instagram: true);
                }
            }
        }
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

        // Meta has its answer first; the lead or the chat message is then made straight away, not at the scheduler's next minute.
        RunSoon::dispatch(new ProcessMetaEvent((int) $event->getKey()));
    }
}
