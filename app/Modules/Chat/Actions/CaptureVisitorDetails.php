<?php

declare(strict_types=1);

namespace App\Modules\Chat\Actions;

use App\Modules\Brands\Models\Brand;
use App\Modules\Chat\Models\ChatSession;
use App\Modules\Chat\Models\ChatVisitor;
use App\Modules\Chat\Models\ChatWidget;
use App\Modules\Chat\Support\LiveChat;
use App\Modules\Chat\Support\VisitorChats;
use App\Modules\Chat\Support\VisitorSpam;
use App\Modules\Core\Support\Audit;
use App\Modules\Leads\Actions\CreateLead;
use App\Modules\Leads\Enums\LeadSource;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Support\PhoneNormalizer;
use App\Modules\Meta\Models\Message;

/**
 * The lead gate (user decision, 3 Nov 2026): a visitor becomes a lead only when they give a valid email
 * address or phone number, in the pre-chat form, the details card in the chat, or by saying yes to the one
 * the chat found in their message. A name alone is kept on the visitor and makes no lead. The lead is made by
 * CreateLead, so a person already in the brand's leads (the same email or phone) gets this chat added to
 * their lead and no second one.
 */
final class CaptureVisitorDetails
{
    /**
     * @param  array{name?: mixed, email?: mixed, phone?: mixed}  $input
     * @return array{lead: ?Lead, errors: array<string, string>}
     */
    public function handle(ChatWidget $widget, ChatVisitor $visitor, array $input, ?string $firstMessage = null, ?string $page = null): array
    {
        $brand = Brand::query()->findOrFail($visitor->brand_id);

        $name = mb_substr(trim(strip_tags((string) ($input['name'] ?? ''))), 0, 190);
        $emailInput = mb_strtolower(trim((string) ($input['email'] ?? '')));
        $phoneInput = trim((string) ($input['phone'] ?? ''));
        $errors = [];
        $email = null;
        $phone = null;

        if ($emailInput !== '') {
            if (mb_strlen($emailInput) > 190 || filter_var($emailInput, FILTER_VALIDATE_EMAIL) === false || VisitorSpam::disposable($emailInput)) {
                $errors['email'] = 'Please check your email address.';
            } else {
                $email = $emailInput;
            }
        }

        if ($phoneInput !== '') {
            $phone = mb_strlen($phoneInput) <= 40 ? PhoneNormalizer::strict($phoneInput, $brand) : null;

            if ($phone === null) {
                $errors['phone'] = 'Please check your phone number, with the country code if it is not local.';
            }
        }

        if ($errors !== []) {
            return ['lead' => null, 'errors' => $errors];
        }

        // Kept on the visitor whatever happens next: a name alone is not a lead.
        $visitor->forceFill(array_filter(['name' => $name !== '' ? $name : null, 'email' => $email, 'phone' => $phone], fn (mixed $value): bool => $value !== null))->save();

        if ($visitor->lead_id !== null) {
            return ['lead' => Lead::query()->find($visitor->lead_id), 'errors' => []];
        }

        if ($email === null && $phone === null) {
            return ['lead' => null, 'errors' => []];
        }

        $conversation = VisitorChats::conversationFor($visitor, $widget, true);
        $first = $firstMessage ?? ($conversation !== null
            ? Message::query()->where('conversation_id', $conversation->getKey())->where('direction', Message::IN)->orderBy('id')->value('body')
            : null);

        $creation = app(CreateLead::class)->handle($brand, [
            'name' => $name !== '' ? $name : ($visitor->name ?? ''),
            'email' => $email,
            'phone' => $phone,
            'message' => is_string($first) ? $first : null,
            'source_type' => LeadSource::LiveChat,
            'source_id' => $widget->getKey(),
            'fields' => self::visitFacts($visitor),
        ]);

        $lead = $creation->lead;

        $visitor->forceFill(['lead_id' => $lead->getKey()])->save();
        $conversation?->forceFill(['lead_id' => $lead->getKey(), 'contact_name' => $name !== '' ? $name : $lead->name])->save();

        Audit::log(
            'chat.lead_from_chat',
            $lead,
            ['widget' => $widget->name, 'page' => $page !== null ? mb_substr($page, 0, 300) : null, 'joined_existing_lead' => $creation->wasMerged],
            'Live chat on '.$widget->name.($creation->wasMerged ? ' joined this lead' : ' started this lead'),
            brand: $brand,
            logName: 'leads',
        );

        return ['lead' => $lead, 'errors' => []];
    }

    /**
     * Where the visitor's latest visit came from, for the lead's source facts (Came from, Campaign, Page).
     *
     * @return array<string, string>
     */
    private static function visitFacts(ChatVisitor $visitor): array
    {
        if (! LiveChat::trackingReady()) {
            return [];
        }

        $session = ChatSession::query()->where('chat_visitor_id', $visitor->getKey())->latest('last_seen_at')->first();

        if ($session === null) {
            return [];
        }

        return array_filter([
            'came_from' => $session->cameFrom(),
            'campaign' => LiveChat::sourceReady() ? $session->campaign : null,
            'page_url' => $session->current_url,
        ], fn (?string $value): bool => $value !== null && $value !== '');
    }
}
