<?php

declare(strict_types=1);

namespace App\Modules\Chat\Support;

/**
 * Where a visit came from, in plain words (user request, 12 Nov 2026). An ad's click tag says which ad network (its value
 * is never kept), the utm tags say what the marketer named the source, medium and campaign, and otherwise the website the
 * browser came from is named: Google search, Facebook, ChatGPT, or the website's own name.
 */
final class VisitSource
{
    /** Tags kept from the landing page's address; everything else in it is dropped. */
    public const TAGS = ['utm_source', 'utm_medium', 'utm_campaign', 'gclid', 'gbraid', 'wbraid', 'fbclid', 'msclkid', 'ttclid', 'li_fat_id', 'twclid', 'epik', 'sccid'];

    private const CLICK_IDS = [
        'gclid' => 'Google Ads', 'gbraid' => 'Google Ads', 'wbraid' => 'Google Ads',
        'msclkid' => 'Microsoft Ads', 'ttclid' => 'TikTok Ads', 'li_fat_id' => 'LinkedIn Ads', 'twclid' => 'X Ads',
        'epik' => 'Pinterest Ads', 'sccid' => 'Snapchat Ads', 'fbclid' => 'Facebook / Instagram',
    ];

    private const SITES = [
        '/(^|\.)google\./' => 'Google search', '/(^|\.)bing\.com$/' => 'Bing search', '/(^|\.)yahoo\./' => 'Yahoo search',
        '/(^|\.)duckduckgo\.com$/' => 'DuckDuckGo', '/(^|\.)yandex\./' => 'Yandex', '/(^|\.)baidu\.com$/' => 'Baidu',
        '/(^|\.)(facebook\.com|fb\.com|fb\.me)$/' => 'Facebook', '/(^|\.)instagram\.com$/' => 'Instagram',
        '/(^|\.)(youtube\.com|youtu\.be)$/' => 'YouTube', '/(^|\.)linkedin\.com$|(^|\.)lnkd\.in$/' => 'LinkedIn',
        '/(^|\.)(t\.co|twitter\.com|x\.com)$/' => 'X (Twitter)', '/(^|\.)tiktok\.com$/' => 'TikTok', '/(^|\.)pinterest\./' => 'Pinterest',
        '/(^|\.)reddit\.com$/' => 'Reddit', '/(^|\.)(chatgpt\.com|openai\.com)$/' => 'ChatGPT', '/(^|\.)perplexity\.ai$/' => 'Perplexity',
        '/(^|\.)(gemini\.google\.com)$/' => 'Gemini', '/(^|\.)claude\.ai$/' => 'Claude', '/(^|\.)copilot\.microsoft\.com$/' => 'Copilot',
        '/(^|\.)whatsapp\.com$/' => 'WhatsApp', '/(^|\.)trustpilot\.com$/' => 'Trustpilot',
    ];

    /**
     * @param  array<string, mixed>  $tags  the landing page's tags (only TAGS are read)
     * @return array{source: ?string, campaign: ?string}
     */
    public static function from(?string $referrerHost, array $tags): array
    {
        $text = fn (string $key, int $max): ?string => is_string($tags[$key] ?? null) && trim($tags[$key]) !== ''
            ? mb_substr(trim(strip_tags(str_replace(['_', '+'], ' ', $tags[$key]))), 0, $max)
            : null;

        $campaign = $text('utm_campaign', 120);
        $source = null;

        foreach (self::CLICK_IDS as $key => $label) {
            if (isset($tags[$key]) && $tags[$key] !== '' && $tags[$key] !== false) {
                $source = $label;
                break;
            }
        }

        if ($source === null && ($utm = $text('utm_source', 50)) !== null) {
            $medium = $text('utm_medium', 25);
            $source = mb_substr($utm.($medium !== null ? ' ('.$medium.')' : ''), 0, 80);
        }

        if ($source === null && $referrerHost !== null && $referrerHost !== '') {
            $host = strtolower(preg_replace('/^www\./', '', $referrerHost) ?? $referrerHost);

            foreach (self::SITES as $pattern => $label) {
                if (preg_match($pattern, $host) === 1) {
                    $source = $label;
                    break;
                }
            }

            $source ??= mb_substr($host, 0, 80);
        }

        return ['source' => $source, 'campaign' => $campaign];
    }
}
