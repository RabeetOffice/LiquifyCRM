<?php

declare(strict_types=1);

namespace App\Modules\Meta\Jobs;

use App\Modules\Brands\Models\Brand;
use App\Modules\Core\Notifications\AppNotification;
use App\Modules\Core\Support\Audit;
use App\Modules\Core\Support\CurrentBrand;
use App\Modules\Leads\Actions\CreateLead;
use App\Modules\Leads\Actions\MergeLeads;
use App\Modules\Leads\Enums\LeadSource;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Support\LeadMatcher;
use App\Modules\Meta\Actions\SyncContactProfile;
use App\Modules\Meta\Exceptions\MetaException;
use App\Modules\Meta\Models\Conversation;
use App\Modules\Meta\Models\Message;
use App\Modules\Meta\Models\MetaSource;
use App\Modules\Meta\Support\MetaApp;
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
        $formName = $formId !== '' ? ($graph->formName($page, $formId) ?? 'Form '.$formId) : null;

        $details = array_filter([
            'name' => $normalised['name'],
            'email' => $normalised['email'],
            'phone' => $normalised['phone'],
            'message' => $normalised['message'],
            'fields' => array_filter([
                'facebook_page' => $page->name,
                'form' => $formName,
                'campaign' => $data['campaign_name'] ?? null,
                'ad' => $data['ad_name'] ?? null,
                'platform' => $data['platform'] ?? null,
                ...$normalised['fields'],
            ]),
            'utm' => $normalised['utm'] !== [] ? $normalised['utm'] : null,
        ], fn (mixed $value): bool => $value !== null);

        // The form names the person's Messenger chat. Somebody who already writes to the Page is one
        // person, not two leads: the form's answers join the lead of that chat.
        $psid = LeadMatcher::messengerId($details['fields']);
        $chat = $psid !== null
            ? Conversation::query()->with('lead.status')->where('meta_source_id', $page->getKey())->where('contact_external_id', $psid)->first()
            : null;

        if ($chat?->lead !== null && $chat->lead->isOpen()) {
            $lead = app(MergeLeads::class)->join(
                $chat->lead,
                [...$details, 'fields' => [...$details['fields'], 'leadgen_id' => $leadgenId]],
                'leads.form_joined',
                'Filled in '.($formName !== null ? 'the lead form "'.$formName.'"' : 'a lead form').' on Facebook: the same person as this chat',
            );

            $lead->loadMissing('assignee');
            $lead->assignee?->notify(new AppNotification(
                $lead->name.' filled in your lead form',
                'The same person as the Messenger chat. Their answers are on the lead now.',
                'info',
                $brand->url('leads/'.$lead->getKey()),
                icon: 'heroicon-o-clipboard-document-list',
                actionLabel: 'Open lead',
            ));

            return $lead;
        }

        $lead = $createLead->handle($brand, [
            ...$details,
            'source_type' => LeadSource::Facebook,
            'source_id' => $page->getKey(),
            'external_id' => $leadgenId,
            ...($normalised['created_at'] !== null ? ['created_at' => $normalised['created_at']] : []),
        ])->lead;

        // A chat that was there but had no open lead belongs to this one from now on.
        if ($chat !== null && $lead->isOpen()) {
            $chat->forceFill(['lead_id' => $lead->getKey()])->save();
        }

        return $lead;
    }

    private function message(InboundEvent $event, MetaSource $page, Brand $brand, MetaGraph $graph, CreateLead $createLead): ?Lead
    {
        $payload = $event->payload;
        $psid = (string) ($payload['sender']['id'] ?? '');
        $message = (array) ($payload['message'] ?? $payload['postback'] ?? []);
        $mid = (string) ($message['mid'] ?? $event->external_id ?? '');

        $text = $this->text($message);
        $sentAt = isset($payload['timestamp']) ? CarbonImmutable::createFromTimestampMs((int) $payload['timestamp']) : now();

        // The Page's own messages come back as "echoes": a reply written on Facebook itself joins the chat.
        if (! empty($message['is_echo'])) {
            return $this->echo($page, $payload, $message, $mid, $text, $sentAt);
        }

        if ($psid === '' || $psid === $page->external_id) {
            return null;
        }

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
                'meta_source_id' => $page->getKey(),
            ]);
            $conversation->setRelation('lead', null);
        }

        $conversation->setRelation('source', $page);
        app(SyncContactProfile::class)->handle($conversation);

        $lead = $conversation->lead;
        $newLead = false;

        // Somebody who filled in a lead form and now writes to the Page is one person: the form
        // named this chat, so the chat joins that lead instead of starting a second one.
        if ($lead === null || ! $lead->isOpen()) {
            $known = LeadMatcher::byMessenger($brand, $psid);

            if ($known !== null) {
                $lead = $known;

                Audit::log('leads.chat_joined', $lead, ['message' => $text], 'Wrote on Messenger: the same person as this lead form', brand: $brand, logName: 'leads');
            }
        }

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

    /**
     * A reply the team wrote on Facebook itself (the Page's inbox, the phone
     * app) joins the chat as an outgoing message. What LiquifyCRM sent is
     * already there, so the echo of our own app is left out.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $message
     */
    private function echo(MetaSource $page, array $payload, array $message, string $mid, string $text, CarbonImmutable $sentAt): ?Lead
    {
        $psid = (string) ($payload['recipient']['id'] ?? '');

        if ($psid === '' || $psid === $page->external_id || (MetaApp::id() !== '' && (string) ($message['app_id'] ?? '') === MetaApp::id())) {
            return null;
        }

        $conversation = Conversation::query()
            ->with('lead')
            ->where('meta_source_id', $page->getKey())
            ->where('contact_external_id', $psid)
            ->first();

        if ($conversation === null) {
            return null;
        }

        if ($mid === '' || ! Message::query()->where('external_id', $mid)->exists()) {
            Message::query()->create([
                'conversation_id' => $conversation->getKey(),
                'direction' => Message::OUT,
                'body' => $text,
                'external_id' => $mid !== '' ? $mid : null,
                'status' => 'sent',
                'raw' => $payload,
                'created_at' => $sentAt,
            ]);

            $conversation->forceFill([
                'last_message_at' => $sentAt,
                'last_message_preview' => mb_substr('You: '.$text, 0, 190),
                'unread_count' => 0,
            ])->save();

            $conversation->lead?->touchActivity();
        }

        return $conversation->lead;
    }

    /**
     * What a message says. One with only pictures or files says how many, and the thread shows them.
     *
     * @param  array<string, mixed>  $message
     */
    private function text(array $message): string
    {
        $text = trim((string) ($message['text'] ?? $message['title'] ?? ''));

        if ($text !== '') {
            return $text;
        }

        $attachments = count((array) ($message['attachments'] ?? []));

        return $attachments > 0 ? '['.$attachments.' '.str('attachment')->plural($attachments).']' : '[Message without text]';
    }
}
