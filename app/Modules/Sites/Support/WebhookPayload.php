<?php

declare(strict_types=1);

namespace App\Modules\Sites\Support;

/**
 * Turns what a form builder posts (Elementor's "Webhook" action, with or
 * without "Advanced data") into the same shape liquify.php sends.
 */
final class WebhookPayload
{
    private const META_KEYS = ['date', 'time', 'page_url', 'user_agent', 'remote_ip', 'powered_by', 'form_id', 'form_name', 'credit'];

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function fromRequest(array $input): array
    {
        // Already in the LiquifyCRM shape (a custom integration posting JSON).
        if (isset($input['fields']) && is_array($input['fields']) && ! self::looksAdvanced($input['fields'])) {
            return [
                'form' => (string) ($input['form'] ?? 'website'),
                'external_id' => (string) ($input['external_id'] ?? ''),
                'submitted_at' => (string) ($input['submitted_at'] ?? now()->toIso8601String()),
                'fields' => $input['fields'],
                'meta' => is_array($input['meta'] ?? null) ? $input['meta'] : [],
            ];
        }

        $fields = [];
        $meta = [];
        $form = 'website';

        if (isset($input['fields']) && is_array($input['fields'])) {
            // Elementor with "Advanced data": fields[id][value], form[name], meta[page_url][value].
            foreach ($input['fields'] as $id => $field) {
                $key = is_array($field) ? (string) ($field['title'] ?? $field['id'] ?? $id) : (string) $id;
                $fields[$key !== '' ? $key : (string) $id] = is_array($field) ? ($field['value'] ?? '') : $field;
            }

            $form = (string) (is_array($input['form'] ?? null) ? ($input['form']['name'] ?? 'website') : 'website');

            foreach ((array) ($input['meta'] ?? []) as $key => $value) {
                $meta[(string) $key] = is_array($value) ? ($value['value'] ?? '') : $value;
            }
        } else {
            // Elementor simple mode: one entry per field label, plus a few fixed keys.
            foreach ($input as $key => $value) {
                $normalised = self::key((string) $key);

                if (in_array($normalised, self::META_KEYS, true)) {
                    $meta[$normalised] = $value;
                } else {
                    $fields[(string) $key] = $value;
                }
            }

            $form = (string) ($meta['form_name'] ?? 'website');
        }

        return [
            'form' => $form !== '' ? $form : 'website',
            'external_id' => '',
            'submitted_at' => now()->toIso8601String(),
            'fields' => $fields,
            'meta' => array_filter([
                'page_url' => $meta['page_url'] ?? null,
                'ip' => $meta['remote_ip'] ?? null,
                'user_agent' => $meta['user_agent'] ?? null,
            ]),
        ];
    }

    /** @param  array<array-key, mixed>  $fields */
    private static function looksAdvanced(array $fields): bool
    {
        $first = reset($fields);

        return is_array($first) && array_key_exists('value', $first);
    }

    private static function key(string $key): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', '_', mb_strtolower($key)), '_');
    }
}
