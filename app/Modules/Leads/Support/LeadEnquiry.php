<?php

declare(strict_types=1);

namespace App\Modules\Leads\Support;

use App\Modules\Leads\Enums\LeadSource;
use App\Modules\Leads\Models\Lead;
use Carbon\CarbonInterface;

/**
 * What a lead's form carried, sorted for reading. The extra fields of a
 * lead hold two different things in one bag: what the person answered
 * ("What stage is your book at?") and where the enquiry came from (the
 * Facebook Page, the form, the campaign). The lead screen shows the answers
 * up front and the source apart, and writes both the way a person would:
 * "fully_written_and_ready_to_publish" is "Fully written and ready to
 * publish".
 */
final class LeadEnquiry
{
    /** Where the enquiry came from, in the order it is shown, with the word for each. */
    private const SOURCE = [
        'facebook_page' => 'Facebook Page',
        'instagram' => 'Instagram account',
        'website' => 'Website',
        'form' => 'Form',
        'campaign' => 'Campaign',
        'adset' => 'Ad set',
        'ad' => 'Ad',
        'platform' => 'Platform',
        'page_url' => 'Page',
        'traffic_source' => 'Heard of us through',
    ];

    /** Shown under Details. */
    private const DETAILS = ['company', 'address'];

    /** Kept for the machine, never listed (the chat link is offered as a button). */
    private const HIDDEN = ['inbox_url', 'leadgen_id', 'ad_id'];

    private const PLATFORMS = ['fb' => 'Facebook', 'ig' => 'Instagram', 'msg' => 'Messenger', 'an' => 'Audience Network', 'wa' => 'WhatsApp'];

    /**
     * What the person answered, question and answer.
     *
     * @return list<array{label: string, value: string}>
     */
    public static function answers(Lead $lead): array
    {
        $fields = (array) $lead->fields;
        $answers = [];

        // The service they want is the first thing anybody asks about a lead.
        $service = self::text($fields['service'] ?? null);

        if ($service !== '') {
            $answers[] = ['label' => 'Service', 'value' => self::answer($service)];
        }

        foreach ($fields as $key => $value) {
            $key = (string) $key;

            if ($key === 'service' || isset(self::SOURCE[$key]) || in_array($key, self::DETAILS, true) || in_array($key, self::HIDDEN, true)) {
                continue;
            }

            $value = self::text($value);

            if ($value !== '') {
                $answers[] = ['label' => self::question($key), 'value' => self::answer($value)];
            }
        }

        return $answers;
    }

    /**
     * Where the enquiry came from: the source's own facts, then the campaign
     * tags of the link the person followed.
     *
     * @return list<array{label: string, value: string, link: ?string}>
     */
    public static function source(Lead $lead): array
    {
        $fields = (array) $lead->fields;
        $facts = [];

        foreach (self::SOURCE as $key => $label) {
            $value = self::text($fields[$key] ?? null);

            if ($value === '') {
                continue;
            }

            $facts[] = [
                'label' => $label,
                'value' => $key === 'platform' ? (self::PLATFORMS[mb_strtolower($value)] ?? $value) : $value,
                'link' => preg_match('~^https?://~i', $value) ? $value : null,
            ];
        }

        foreach ((array) $lead->utm as $key => $value) {
            $value = self::text($value);

            if ($value !== '') {
                $facts[] = ['label' => self::tag((string) $key), 'value' => $value, 'link' => null];
            }
        }

        return $facts;
    }

    /**
     * What they asked for, by where each part came from (user report, 4 Nov
     * 2026: after a form and a chat became one lead, nobody could tell the
     * Messenger message from the form's answers). The form's answers (and a
     * website's or a form's own message) are one part; the chat's message is
     * another, shown as a chat. Oldest first, the form first when a time is
     * missing. `$chat` is the lead's chat: the person's first message in it,
     * and whether the lead's message is one of theirs.
     *
     * @param  array{channel: string, icon: string, first: ?string, firstAt: ?CarbonInterface, hasMessage: bool}|null  $chat
     * @return list<array{kind: string, title: string, detail: ?string, icon: string, brand: bool, at: ?CarbonInterface, message: ?string, answers: list<array{label: string, value: string}>}>
     */
    public static function parts(Lead $lead, ?array $chat = null, ?CarbonInterface $formAt = null): array
    {
        $answers = self::answers($lead);
        $message = filled($lead->message) ? (string) $lead->message : null;
        $chatLead = in_array($lead->source_type, [LeadSource::Messenger, LeadSource::Instagram], true);
        $fromChat = $message !== null && ($chatLead || ($chat['hasMessage'] ?? false));
        $parts = [];

        if ($answers !== [] || ($message !== null && ! $fromChat)) {
            $parts[] = [
                'kind' => 'form',
                ...self::formLabel($lead, $chatLead),
                'at' => $formAt,
                'message' => $fromChat ? null : $message,
                'answers' => $answers,
            ];
        }

        $said = $fromChat ? $message : ($chat['first'] ?? null);

        if ($said !== null && $said !== '') {
            $instagram = ($chat['icon'] ?? null) === 'instagram' || ($chat === null && $lead->source_type === LeadSource::Instagram);

            $parts[] = [
                'kind' => 'chat',
                'title' => $chat['channel'] ?? ($instagram ? 'Instagram' : 'Messenger'),
                'detail' => null,
                'icon' => $instagram ? 'instagram' : 'messenger',
                'brand' => true,
                'at' => $chat['firstAt'] ?? null,
                'message' => $said,
                'answers' => [],
            ];
        }

        if (count($parts) === 2 && $parts[0]['at'] !== null && $parts[1]['at'] !== null && $parts[1]['at']->lt($parts[0]['at'])) {
            $parts = [$parts[1], $parts[0]];
        }

        return $parts;
    }

    /**
     * What the form part is called: the lead form of Facebook or Instagram
     * (also when it joined a chat's lead), the website's form, or how the lead
     * was added.
     *
     * @return array{title: string, detail: ?string, icon: string, brand: bool}
     */
    private static function formLabel(Lead $lead, bool $chatLead): array
    {
        $fields = (array) $lead->fields;
        $form = self::text($fields['form'] ?? null);
        $instagram = mb_strtolower(self::text($fields['platform'] ?? null)) === 'ig';

        return match (true) {
            $lead->source_type === LeadSource::Facebook, $chatLead => ['title' => ($instagram ? 'Instagram' : 'Facebook').' lead form', 'detail' => $form !== '' ? $form : null, 'icon' => $instagram ? 'instagram' : 'facebook', 'brand' => true],
            $lead->source_type === LeadSource::Website => ['title' => 'Website form', 'detail' => self::text($fields['website'] ?? null) ?: null, 'icon' => 'heroicon-o-globe-alt', 'brand' => false],
            $lead->source_type === LeadSource::Manual => ['title' => 'Added by hand', 'detail' => null, 'icon' => 'heroicon-o-pencil-square', 'brand' => false],
            $lead->source_type === LeadSource::Import => ['title' => 'Imported', 'detail' => null, 'icon' => 'heroicon-o-arrow-up-tray', 'brand' => false],
            default => ['title' => $lead->source_type->getLabel(), 'detail' => null, 'icon' => 'heroicon-o-inbox-arrow-down', 'brand' => false],
        };
    }

    /** The lead's chat in the Page's own inbox on Facebook, when the form named it. */
    public static function inboxUrl(Lead $lead): ?string
    {
        $url = self::text(((array) $lead->fields)['inbox_url'] ?? null);

        return preg_match('~^https://(business|www)\.facebook\.com/~i', $url) ? $url : null;
    }

    /**
     * The lead in one piece of text for the one-click copy (user request,
     * 19 Oct 2026): name, phone, email and what they asked for, ready to paste
     * into a chat or a note. Contact details go as the person sees them: a
     * masked number stays masked.
     */
    public static function copyText(Lead $lead, bool $fullContact): string
    {
        $phone = $fullContact ? $lead->phone : $lead->maskedPhone();
        $email = $fullContact ? $lead->email : $lead->maskedEmail();

        $lines = [$lead->name];

        // The name to search for in Messenger or Instagram, when it is not the one above.
        if (($profile = ProfileNames::shown($lead)) !== null) {
            $lines[] = $profile['where'].' name: '.$profile['name'];
        }

        if (filled($phone)) {
            $lines[] = 'Phone: '.$phone;
        }

        if (filled($email)) {
            $lines[] = 'Email: '.$email;
        }

        $lines[] = 'Source: '.$lead->source_type->getLabel();

        $asked = [];

        if (filled($lead->message)) {
            $asked[] = 'Message: '.trim((string) $lead->message);
        }

        foreach (self::answers($lead) as $answer) {
            // A question keeps its own question mark: "What stage is your book at? Ready to publish".
            $asked[] = (str_ends_with($answer['label'], '?') ? $answer['label'].' ' : $answer['label'].': ').$answer['value'];
        }

        if ($asked !== []) {
            $lines[] = '';
            $lines[] = 'What they asked for';
            array_push($lines, ...$asked);
        }

        return implode("\n", $lines);
    }

    /** "what_stage_is_your_book_at_right_now" as "What stage is your book at right now?". */
    private static function question(string $key): string
    {
        $label = self::sentence($key);

        return preg_match('~^(what|which|how|when|where|who|why|do|does|did|is|are|can|could|would|will|have|has)\b~i', $label) && ! preg_match('~[?.!:]$~', $label)
            ? $label.'?'
            : $label;
    }

    /** A chosen option arrives as its machine name; free text is left exactly as it was typed. */
    private static function answer(string $value): string
    {
        return str_contains($value, '_') && ! str_contains($value, ' ') && ! preg_match('~^https?://|@~i', $value)
            ? self::sentence($value)
            : $value;
    }

    private static function sentence(string $text): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', str_replace('_', ' ', $text)));
        $text = (string) preg_replace('/\bi\b/u', 'I', $text);

        return mb_strtoupper(mb_substr($text, 0, 1)).mb_substr($text, 1);
    }

    private static function tag(string $key): string
    {
        return match ($key) {
            'utm_source' => 'Campaign source',
            'utm_medium' => 'Campaign medium',
            'utm_campaign' => 'Campaign name',
            'utm_term' => 'Search term',
            'utm_content' => 'Ad content',
            'gclid' => 'Google click',
            'fbclid' => 'Facebook click',
            default => self::sentence($key),
        };
    }

    private static function text(mixed $value): string
    {
        if (is_array($value)) {
            $value = implode(', ', array_filter(array_map(fn (mixed $item): string => is_scalar($item) ? (string) $item : '', $value), fn (string $item): bool => $item !== ''));
        }

        return is_scalar($value) ? trim((string) $value) : '';
    }
}
