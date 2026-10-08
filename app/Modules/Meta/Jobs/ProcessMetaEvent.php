<?php

declare(strict_types=1);

namespace App\Modules\Meta\Jobs;

use App\Modules\Brands\Models\Brand;
use App\Modules\Core\Notifications\AppNotification;
use App\Modules\Core\Support\CurrentBrand;
use App\Modules\Leads\Actions\CreateLead;
use App\Modules\Leads\Enums\LeadSource;
use App\Modules\Leads\Models\Lead;
use App\Modules\Meta\Exceptions\MetaException;
use App\Modules\Meta\Models\Conversation;
use App\Modules\Meta\Models\Message;
use App\Modules\Meta\Models\MetaSource;
use App\Modules\Meta\Support\MetaGraph;
use App\Modules\Sites\Enums\InboundStatus;
use App\Modules\Sites\Exceptions\LeadRejected;
use App\Modules\Sites\Models\InboundEvent;
use App\Modules\Sites\Support\SiteLeadNormalizer;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * One stored Meta webhook event, from the queue: a Lead Ads lead is fetched
 * from the Graph API and becomes a lead of the Page's brand; a Messenger
 * message joins its conversation by PSID (the first one creates the lead).
 * A failure stays on the event with a Retry button.
 */
final class ProcessMetaEvent implements ShouldQueue
{
    use Queueable;

    public const LEADGEN = 'meta_leadgen';

    public const MESSAGE = 'meta_message';

    public int $tries = 1;

    public int $timeout = 90;

    public function __construct(public readonly int $eventId) {}

    public function handle(MetaGraph $graph, CreateLead $createLead, SiteLeadNormalizer $normalizer): void
    {
        $event = InboundEvent::query()->find($this->eventId);

        if ($event === null || $event->status === InboundStatus::Done) {
            return;
        }

        $event->increment('attempts');

        try {
            $page = MetaSource::query()->with('brand')->where('external_id', (string) ($event->payload['page_id'] ?? ''))->first();

            if ($page === null) {
                throw new LeadRejected('This Facebook Page is not connected. Click "Refresh Pages" on the Integrations screen.');
            }

            $brand = $page->brand;

            if ($brand === null) {
                throw new LeadRejected('The Page "'.$page->name.'" is not mapped to a brand yet. Map it on the Integrations screen; this is retried automatically.');
            }

            if ($event->brand_id !== $brand->getKey() || $event->source_id !== $page->getKey()) {
                $event->forceFill(['brand_id' => $brand->getKey(), 'source_id' => $page->getKey()])->save();
            }

            $lead = app(CurrentBrand::class)->within($brand, fn (): ?Lead => $event->source === self::LEADGEN
                ? $this->leadAd($event, $page, $brand, $graph, $createLead, $normalizer)
                : $this->message($event, $page, $brand, $graph, $createLead));

            $page->forceFill(['last_event_at' => now()])->save();
            $event->markDone($lead);
        } catch (Throwable $exception) {
            $event->markFailed($exception->getMessage());

            if (! $exception instanceof LeadRejected && ! $exception instanceof MetaException) {
                report($exception);
            }
        }
    }

    private function leadAd(InboundEvent $event, MetaSource $page, Brand $brand, MetaGraph $graph, CreateLead $createLead, SiteLeadNormalizer $normalizer): Lead
    {
        $leadgenId = (string) $event->external_id;

        $existing = Lead::query()->withTrashed()->where('source_type', LeadSource::Facebook->value)->where('external_id', $leadgenId)->first();

        if ($existing !== null) {
            return $existing;
        }

        // The webhook only names the lead; its answers come from the Graph API.
        $data = $graph->lead($page, $leadgenId);

        $fields = [];

        foreach ((array) ($data['field_data'] ?? []) as $field) {
            if (is_array($field) && filled($field['name'] ?? null)) {
                $fields[(string) $field['name']] = implode(', ', array_map('strval', (array) ($field['values'] ?? [])));
            }
        }

        $normalised = $normalizer->normalise(['fields' => $fields, 'submitted_at' => $data['created_time'] ?? null]);

        if ($normalised['name'] === null && $normalised['email'] === null && $normalised['phone'] === null) {
            throw new LeadRejected('The lead form sent no name, email or phone number.');
        }

        $formId = (string) ($data['form_id'] ?? $event->payload['form_id'] ?? '');

        $result = $createLead->handle($brand, array_filter([
            'source_type' => LeadSource::Facebook,
            'source_id' => $page->getKey(),
            'external_id' => $leadgenId,
            'name' => $normalised['name'],
            'email' => $normalised['email'],
            'phone' => $normalised['phone'],
            'message' => $normalised['message'],
            'fields' => array_filter([
                'facebook_page' => $page->name,
                'form' => $formId !== '' ? ($graph->formName($page, $formId) ?? 'Form '.$formId) : null,
                'campaign' => $data['campaign_name'] ?? null,
                'ad' => $data['ad_name'] ?? null,
                'platform' => $data['platform'] ?? null,
                ...$normalised['fields'],
            ]),
            'utm' => $normalised['utm'] !== [] ? $normalised['utm'] : null,
            'created_at' => $normalised['created_at'],
        ], fn (mixed $value): bool => $value !== null));

        return $result->lead;
    }

    private function message(InboundEvent $event, MetaSource $page, Brand $brand, MetaGraph $graph, CreateLead $createLead): ?Lead
    {
        $payload = $event->payload;
        $psid = (string) ($payload['sender']['id'] ?? '');
        $message = (array) ($payload['message'] ?? $payload['postback'] ?? []);
        $mid = (string) ($message['mid'] ?? $event->external_id ?? '');

        // Echoes are the Page's own messages coming back; nothing to store.
        if ($psid === '' || $psid === $page->external_id || ! empty($message['is_echo'])) {
            return null;
        }

        $text = trim((string) ($message['text'] ?? $message['title'] ?? ''));

        if ($text === '') {
            $attachments = count((array) ($message['attachments'] ?? []));
            $text = $attachments > 0 ? '['.$attachments.' '.str('attachment')->plural($attachments).']' : '[Message without text]';
        }

        $sentAt = isset($payload['timestamp']) ? CarbonImmutable::createFromTimestampMs((int) $payload['timestamp']) : now();

        $conversation = Conversation::query()
            ->with('lead.status')
            ->where('meta_source_id', $page->getKey())
            ->where('contact_external_id', $psid)
            ->first();

        if ($conversation === null) {
            $conversation = Conversation::query()->create([
                'brand_id' => $brand->getKey(),
                'channel' => Conversation::CHANNEL_MESSENGER,
                'contact_external_id' => $psid,
                'contact_name' => $graph->contactName($page, $psid),
                'meta_source_id' => $page->getKey(),
            ]);
            $conversation->setRelation('lead', null);
        }

        $lead = $conversation->lead;
        $newLead = false;

        // A new chat, or one whose lead was closed or removed, starts a lead in the New queue.
        if ($lead === null || ! $lead->isOpen()) {
            $lead = $createLead->handle($brand, [
                'source_type' => LeadSource::Messenger,
                'source_id' => $page->getKey(),
                'external_id' => $psid.':'.$conversation->getKey().':'.$sentAt->getTimestamp(),
                'name' => $conversation->displayName(),
                'message' => $text,
                'fields' => ['facebook_page' => $page->name],
            ], mergeDuplicates: false)->lead;
            $newLead = true;
        }

        if ($mid === '' || ! Message::query()->where('external_id', $mid)->exists()) {
            Message::query()->create([
                'conversation_id' => $conversation->getKey(),
                'direction' => Message::IN,
                'body' => $text,
                'external_id' => $mid !== '' ? $mid : null,
                'status' => 'delivered',
                'raw' => $payload,
                'created_at' => $sentAt,
            ]);

            $conversation->forceFill([
                'lead_id' => $lead->getKey(),
                'last_inbound_at' => $sentAt,
                'last_message_at' => $sentAt,
                'last_message_preview' => mb_substr($text, 0, 190),
                'unread_count' => $conversation->unread_count + 1,
            ])->save();

            $lead->touchActivity();

            // CreateLead already told the right people about a new lead.
            if (! $newLead && $lead->assigned_to !== null) {
                $lead->loadMissing('assignee');
                $lead->assignee?->notify(new AppNotification(
                    'New message from '.$conversation->displayName(),
                    mb_substr($text, 0, 140),
                    'info',
                    $brand->url('inbox?chat='.$conversation->getKey()),
                    icon: 'heroicon-o-chat-bubble-left-right',
                    actionLabel: 'Open chat',
                ));
            }
        }

        return $lead;
    }
}
