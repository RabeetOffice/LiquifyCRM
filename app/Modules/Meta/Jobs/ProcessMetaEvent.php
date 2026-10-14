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
use App\Modules\Meta\Support\Instagram;
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
 * message joins its conversation by PSID, an Instagram direct message by
 * IGSID (the first one creates the lead). An Instagram account belongs to the
 * brand of the Page it is linked to. A failure stays on the event with a
 * Retry button.
 */
final class ProcessMetaEvent implements ShouldQueue
{
    use Queueable;

    public const LEADGEN = 'meta_leadgen';

    public const MESSAGE = 'meta_message';

    public const INSTAGRAM = 'meta_instagram';

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
            $page = $this->page($event);
            $brand = $page->brand;

            if ($brand === null) {
                throw new LeadRejected('The Page "'.$page->name.'" is not mapped to a brand yet. Map it on the Integrations screen; this is retried automatically.');
            }

            if ($event->brand_id !== $brand->getKey() || $event->source_id !== $page->getKey()) {
                $event->forceFill(['brand_id' => $brand->getKey(), 'source_id' => $page->getKey()])->save();
            }

            $lead = app(CurrentBrand::class)->within($brand, fn (): ?Lead => $event->source === self::LEADGEN
                ? $this->leadAd($event, $page, $brand, $graph, $createLead, $normalizer)
                : $this->message($event, $page, $brand, $createLead));

            $page->forceFill(['last_event_at' => now()])->save();
            $event->markDone($lead);
        } catch (Throwable $exception) {
            $event->markFailed($exception->getMessage());

            if (! $exception instanceof LeadRejected && ! $exception instanceof MetaException) {
                report($exception);
            }
        }
    }

    /** The Page the event belongs to: named by the event, or the Page an Instagram account is linked to. */
    private function page(InboundEvent $event): MetaSource
    {
        if ($event->source !== self::INSTAGRAM) {
            return MetaSource::query()->with('brand')->where('external_id', (string) ($event->payload['page_id'] ?? ''))->first()
                ?? throw new LeadRejected('This Facebook Page is not connected. Click "Refresh Pages" on the Integrations screen.');
        }

        if (! Instagram::ready()) {
            throw new LeadRejected('Instagram messages need the latest version: an Admin clicks "Update system" on the Updates screen, then "Refresh Pages" on the Integrations screen. This message is processed then.');
        }

        $account = (string) ($event->payload['instagram_id'] ?? '');
        $page = $account !== '' ? MetaSource::query()->with('brand')->where('instagram_id', $account)->first() : null;

        return $page ?? throw new LeadRejected('This Instagram account is not linked to a connected Facebook Page yet. Link it to its Page in Meta Business Suite, then click "Refresh Pages" on the Integrations screen; the message is processed then.');
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

    /**
     * A Messenger or Instagram message. Both work alike: the person is known
     * by the id Meta gives them for that Page or account, the first message
     * starts a lead in the New queue and later ones join its chat.
     */
    private function message(InboundEvent $event, MetaSource $page, Brand $brand, CreateLead $createLead): ?Lead
    {
        $payload = $event->payload;
        $instagram = $event->source === self::INSTAGRAM;
        $channel = $instagram ? Conversation::CHANNEL_INSTAGRAM : Conversation::CHANNEL_MESSENGER;
        // The Page (Messenger) or its Instagram account: who the person writes to.
        $own = $instagram ? $page->instagramId() : $page->external_id;
        $contactId = (string) ($payload['sender']['id'] ?? '');
        $message = (array) ($payload['message'] ?? $payload['postback'] ?? []);
        $mid = (string) ($message['mid'] ?? $event->external_id ?? '');

        $text = $this->text($message);
        $sentAt = $this->sentAt($payload);

        // The Page's own messages come back as "echoes": a reply written on Facebook or Instagram itself joins the chat.
        if (! empty($message['is_echo'])) {
            return $this->echo($page, $channel, $own, $payload, $message, $mid, $text, $sentAt);
        }

        // Instagram says when the person deleted a message: its words go here too.
        if (! empty($message['is_deleted'])) {
            return $this->deleted($mid);
        }

        if ($contactId === '' || $contactId === $own) {
            return null;
        }

        // No message, only an ad: somebody who already has a chat tapped an ad again.
        if ($message === [] && isset($payload['referral'])) {
            return $this->adTapped($page, $brand, $channel, $contactId, $payload);
        }

        $conversation = Conversation::query()
            ->with('lead.status')
            ->where('meta_source_id', $page->getKey())
            ->where('channel', $channel)
            ->where('contact_external_id', $contactId)
            ->first();

        if ($conversation === null) {
            $conversation = Conversation::query()->create([
                'brand_id' => $brand->getKey(),
                'channel' => $channel,
                'contact_external_id' => $contactId,
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
        if (! $instagram && ($lead === null || ! $lead->isOpen())) {
            $known = LeadMatcher::byMessenger($brand, $contactId);

            if ($known !== null) {
                $lead = $known;

                Audit::log('leads.chat_joined', $lead, ['message' => $text], 'Wrote on Messenger: the same person as this lead form', brand: $brand, logName: 'leads');
            }
        }

        // A new chat, or one whose lead was closed or removed, starts a lead in the New queue.
        if ($lead === null || ! $lead->isOpen()) {
            $lead = $createLead->handle($brand, [
                'source_type' => $instagram ? LeadSource::Instagram : LeadSource::Messenger,
                'source_id' => $page->getKey(),
                'external_id' => $contactId.':'.$conversation->getKey().':'.$sentAt->getTimestamp(),
                'name' => $conversation->displayName(),
                'message' => $text,
                'fields' => array_filter([
                    ...($instagram ? ['instagram' => $page->instagramHandle()] : ['facebook_page' => $page->name]),
                    ...$this->adFields($payload, $message),
                ]),
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
                    ($instagram ? 'New Instagram message from ' : 'New message from ').$conversation->displayName(),
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
     * A reply the team wrote on Facebook or Instagram itself (the Page's
     * inbox, the phone app) joins the chat as an outgoing message. What
     * LiquifyCRM sent is already there, so the echo of our own app is left
     * out: Messenger names the app, and a reply sent from here a moment ago
     * with the same words is the same message.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $message
     */
    private function echo(MetaSource $page, string $channel, ?string $own, array $payload, array $message, string $mid, string $text, CarbonImmutable $sentAt): ?Lead
    {
        $contactId = (string) ($payload['recipient']['id'] ?? '');

        if ($contactId === '' || $contactId === $own || (MetaApp::id() !== '' && (string) ($message['app_id'] ?? '') === MetaApp::id())) {
            return null;
        }

        $conversation = Conversation::query()
            ->with('lead')
            ->where('meta_source_id', $page->getKey())
            ->where('channel', $channel)
            ->where('contact_external_id', $contactId)
            ->first();

        if ($conversation === null) {
            return null;
        }

        if ($mid === '' || ! Message::query()->where('external_id', $mid)->exists()) {
            $ours = Message::query()
                ->where('conversation_id', $conversation->getKey())
                ->where('direction', Message::OUT)
                ->whereNotNull('sent_by')
                ->where('body', $text)
                ->where('created_at', '>=', $sentAt->subMinutes(2))
                ->exists();

            if ($ours) {
                return $conversation->lead;
            }

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
     * Somebody who already writes to the account tapped one of its ads again.
     * The ad goes on the open lead (when it has none yet) and on its timeline;
     * a first chat brings its ad with its first message instead.
     *
     * @param  array<string, mixed>  $payload
     */
    private function adTapped(MetaSource $page, Brand $brand, string $channel, string $contactId, array $payload): ?Lead
    {
        $ad = $this->adFields($payload, []);
        $conversation = Conversation::query()
            ->with('lead.status')
            ->where('meta_source_id', $page->getKey())
            ->where('channel', $channel)
            ->where('contact_external_id', $contactId)
            ->first();
        $lead = $conversation?->lead;

        if ($ad === [] || $conversation === null || $lead === null || ! $lead->isOpen()) {
            return $lead;
        }

        if (blank($lead->fields['ad'] ?? null)) {
            $lead->forceFill(['fields' => [...(array) $lead->fields, ...$ad]])->save();
        }

        Audit::log('leads.ad_tapped', $lead, $ad, 'Tapped the ad "'.$ad['ad'].'" on '.$conversation->channelLabel(), brand: $brand, logName: 'leads');
        $lead->touchActivity();

        return $lead;
    }

    /** The person deleted a message on Instagram: its words and attachments go here too. */
    private function deleted(string $mid): ?Lead
    {
        $message = $mid !== '' ? Message::query()->with('conversation.lead')->where('external_id', $mid)->first() : null;

        if ($message === null) {
            return null;
        }

        $message->forceFill(['body' => '[Deleted by the sender]', 'raw' => null])->save();

        $conversation = $message->conversation;

        if ((int) Message::query()->where('conversation_id', $conversation->getKey())->max('id') === (int) $message->getKey()) {
            $conversation->forceFill(['last_message_preview' => '[Deleted by the sender]'])->save();
        }

        return $conversation->lead;
    }

    /**
     * The ad the person came from, when they started the chat from one
     * ("Send message" on a Facebook or Instagram ad). Kept on the lead so it
     * shows where the enquiry came from.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $message
     * @return array<string, string>
     */
    private function adFields(array $payload, array $message): array
    {
        $referral = (array) ($message['referral'] ?? $payload['referral'] ?? $payload['postback']['referral'] ?? []);
        $adId = (string) ($referral['ad_id'] ?? '');

        if ($adId === '') {
            return [];
        }

        $title = trim((string) ($referral['ads_context_data']['ad_title'] ?? ''));

        return ['ad' => $title !== '' ? $title : 'Ad '.$adId, 'ad_id' => $adId];
    }

    /**
     * When the message was sent. Meta sends milliseconds; its dashboard test
     * sends seconds.
     *
     * @param  array<string, mixed>  $payload
     */
    private function sentAt(array $payload): CarbonImmutable
    {
        $stamp = (int) ($payload['timestamp'] ?? 0);

        if ($stamp <= 0) {
            return CarbonImmutable::now();
        }

        return $stamp < 100_000_000_000 ? CarbonImmutable::createFromTimestamp($stamp) : CarbonImmutable::createFromTimestampMs($stamp);
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

        if (! empty($message['is_unsupported'])) {
            return '[A kind of message Instagram does not pass on. Open the chat on Instagram to see it.]';
        }

        $attachments = (array) ($message['attachments'] ?? []);
        $count = count($attachments);
        $disappearing = array_filter($attachments, fn (mixed $item): bool => is_array($item) && ($item['type'] ?? null) === 'ephemeral');

        if ($count > 0 && count($disappearing) === $count) {
            return '[A disappearing photo or video. Open the chat on Instagram to see it.]';
        }

        return $count > 0 ? '['.$count.' '.str('attachment')->plural($count).']' : '[Message without text]';
    }
}
