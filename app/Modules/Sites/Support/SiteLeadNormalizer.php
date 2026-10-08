<?php

declare(strict_types=1);

namespace App\Modules\Sites\Support;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * Maps whatever a website form sends onto a lead: the known fields (name,
 * email, phone, message) by their many spellings, UTM tags, and everything
 * else kept as extra fields so each site can have different forms.
 * The alias list is carried over from LeadsHub.
 */
final class SiteLeadNormalizer
{
    /** alias => lead column or extra-field name */
    private const ALIASES = [
        'full_name' => 'name', 'fullname' => 'name', 'your_name' => 'name', 'author_name' => 'name', 'contact_name' => 'name',
        'first_name' => 'first_name', 'firstname' => 'first_name', 'fname' => 'first_name',
        'last_name' => 'last_name', 'lastname' => 'last_name', 'surname' => 'last_name', 'lname' => 'last_name',
        'email_address' => 'email', 'mail' => 'email', 'e_mail' => 'email', 'your_email' => 'email',
        'telephone' => 'phone', 'phone_number' => 'phone', 'mobile' => 'phone', 'tel' => 'phone', 'contact_number' => 'phone', 'your_phone' => 'phone',
        'msg' => 'message', 'comments' => 'message', 'comment' => 'message', 'details' => 'message', 'description' => 'message', 'enquiry' => 'message', 'your_message' => 'message',
        'interest' => 'service', 'services' => 'service', 'service_interested' => 'service', 'subject' => 'service', 'package' => 'service',
        'title' => 'book_title', 'book' => 'book_title', 'project' => 'book_title',
        'category' => 'genre',
        'source' => 'traffic_source', 'referrer' => 'traffic_source', 'how_did_you_hear' => 'traffic_source',
        'page' => 'page_url', 'pageurl' => 'page_url', 'fullpageurl' => 'page_url', 'source_page' => 'page_url', 'url' => 'page_url',
        'formtype' => 'form', 'form_type' => 'form', 'form_name' => 'form', 'source_form' => 'form',
        'manuscript_file' => 'attachments', 'file' => 'attachments', 'files' => 'attachments', 'upload' => 'attachments',
        'created' => 'created_at', 'date' => 'created_at', 'timestamp' => 'created_at', 'submitted_at' => 'created_at',
    ];

    /** Never stored: tokens, buttons, technical noise. */
    private const DROPPED = [
        'id', 'remote_id', '_csrf', '_token', 'csrf_token', 'g_recaptcha_response', 'recaptcha_token', 'recaptcha', 'submit',
        'password', 'ip', 'ip_address', 'user_agent', 'ua', 'updated_at', 'status', 'is_read', 'action',
    ];

    /** A person never sees these; a bot fills them. */
    private const HONEYPOTS = ['honeypot', 'website_url_hp', 'hp_field', 'leave_blank'];

    private const TRACKING = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'gclid', 'fbclid'];

    /**
     * @param  array<string, mixed>  $payload
     * @return array{name: ?string, email: ?string, phone: ?string, message: ?string, fields: array<string, string>, utm: array<string, string>, created_at: ?CarbonImmutable, spam: bool}
     */
    public function normalise(array $payload): array
    {
        $lead = ['name' => null, 'email' => null, 'phone' => null, 'message' => null];
        $fields = [];
        $utm = [];
        $spam = false;
        $first = $last = null;
        $createdAt = $this->date($payload['submitted_at'] ?? null);

        foreach ((array) ($payload['fields'] ?? []) as $rawKey => $value) {
            $key = $this->key((string) $rawKey);
            $value = $this->scalar($value);

            if ($key === '' || $value === '') {
                continue;
            }

            if (in_array($key, self::HONEYPOTS, true)) {
                $spam = true;

                continue;
            }

            if (in_array($key, self::DROPPED, true)) {
                continue;
            }

            $target = self::ALIASES[$key] ?? $key;

            if ($target === 'first_name') {
                $first ??= $value;
            } elseif ($target === 'last_name') {
                $last ??= $value;
            } elseif ($target === 'created_at') {
                $createdAt ??= $this->date($value);
            } elseif (in_array($target, self::TRACKING, true)) {
                $utm[$target] ??= mb_substr($value, 0, 255);
            } elseif (! array_key_exists($target, $lead)) {
                $fields[$target] ??= $value;
            } elseif ($lead[$target] === null) {
                $lead[$target] = $value;
            } else {
                // A second spelling of a field that is already filled.
                $fields[$key] ??= $value;
            }
        }

        $lead['name'] ??= trim(($first ?? '').' '.($last ?? '')) ?: null;

        if ($lead['email'] !== null && filter_var($lead['email'], FILTER_VALIDATE_EMAIL) === false) {
            $fields['email_as_typed'] = $lead['email'];
            $lead['email'] = null;
        }

        $form = $this->scalar($payload['form'] ?? '');

        if ($form !== '' && ! isset($fields['form'])) {
            $fields['form'] = $form;
        }

        foreach ((array) ($payload['meta'] ?? []) as $rawKey => $value) {
            $key = $this->key((string) $rawKey);
            $value = $this->scalar($value);

            if ($value === '') {
                continue;
            }

            if (in_array($key, self::TRACKING, true)) {
                $utm[$key] = mb_substr($value, 0, 255);
            } elseif ($key === 'page_url') {
                $fields['page_url'] ??= $value;
            }
        }

        return [
            'name' => $lead['name'] !== null ? mb_substr($lead['name'], 0, 160) : null,
            'email' => $lead['email'] !== null ? mb_substr(mb_strtolower($lead['email']), 0, 190) : null,
            'phone' => $lead['phone'] !== null ? mb_substr($lead['phone'], 0, 64) : null,
            'message' => $lead['message'],
            'fields' => array_map(fn (string $value): string => mb_substr($value, 0, 2000), $fields),
            'utm' => $utm,
            'created_at' => $createdAt,
            'spam' => $spam,
        ];
    }

    private function key(string $key): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', '_', mb_strtolower(trim($key))), '_');
    }

    private function scalar(mixed $value): string
    {
        if (is_array($value)) {
            $value = implode(', ', array_filter(array_map(fn (mixed $item): string => is_scalar($item) ? (string) $item : '', $value), fn (string $item): bool => $item !== ''));
        }

        return is_scalar($value) ? trim((string) $value) : '';
    }

    /** The original date of a lead, never in the future. */
    private function date(mixed $value): ?CarbonImmutable
    {
        if (! is_scalar($value) || trim((string) $value) === '') {
            return null;
        }

        try {
            $date = is_numeric($value) ? CarbonImmutable::createFromTimestamp((int) $value) : CarbonImmutable::parse((string) $value);
        } catch (Throwable) {
            return null;
        }

        return $date->isFuture() || $date->year < 2000 ? null : $date->setTimezone(config('app.timezone'));
    }
}
