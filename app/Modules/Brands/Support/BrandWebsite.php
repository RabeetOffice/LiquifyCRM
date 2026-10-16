<?php

declare(strict_types=1);

namespace App\Modules\Brands\Support;

use App\Modules\Core\Support\Countries;
use DateTimeZone;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Reads what a brand's website says about the company behind it: its name,
 * phone, email and postal address, the currency and time zone that follow
 * from its country, and where its logo and icon are.
 *
 * The details most websites publish for search engines (schema.org) come
 * first; links and tags on the page fill the gaps, and the contact page is
 * read when the homepage leaves something out. A page is kept for five
 * minutes, so the steps of Add brand read it once. Not final so tests can
 * replace it.
 */
class BrandWebsite
{
    /** Seconds a page is kept. */
    private const KEEP = 300;

    /** Other companies' marks on a page: never the brand's own logo. */
    private const NOT_OUR_LOGO = '~slider|carousel|client|customer|partner|payment|badge|award|sponsor|review|testimonial|trust|press|featured|as-seen|retailer|stockist|logo-card|logo-wall|logo-grid~i';

    /** A logo drawn for a dark page ("logo-white.svg"): the last choice, since documents are white. */
    private const FOR_DARK = '~white|light|inverse|inverted|reverse|negative|footer~i';

    /**
     * The homepage, or the reason it could not be read.
     *
     * @return array{html: ?string, error: ?string}
     */
    public function page(string $url, bool $fresh = false): array
    {
        return $this->get(rtrim($url, '/').'/', $fresh);
    }

    /** The name the website gives itself (its tags, its structured data, its title), or null. */
    public function siteName(string $url): ?string
    {
        $url = rtrim($url, '/');
        $html = $this->page($url)['html'];

        return $html === null ? null : $this->read($html, mb_strtolower((string) parse_url($url, PHP_URL_HOST)))['name'];
    }

    /**
     * Everything the website says about the brand. A key is null when the
     * website does not say it.
     *
     * @return array{reachable: bool, name: ?string, legal_name: ?string, phone: ?string, email: ?string, address: ?string, country: ?string, currency: ?string, timezone: ?string, registration_number: ?string, tax_number: ?string, logo: ?string, icon: ?string}
     */
    public function details(string $url, bool $fresh = false): array
    {
        $url = rtrim($url, '/');
        $html = $this->page($url, $fresh)['html'];

        if ($html === null) {
            return [
                'reachable' => false, 'name' => null, 'legal_name' => null, 'phone' => null, 'email' => null, 'address' => null, 'country' => null,
                'currency' => null, 'timezone' => null, 'registration_number' => null, 'tax_number' => null, 'logo' => null, 'icon' => null,
            ];
        }

        $host = mb_strtolower((string) parse_url($url, PHP_URL_HOST));
        $found = $this->read($html, $host);

        // What the homepage leaves out is usually on the contact page.
        if ($found['phone'] === null || $found['email'] === null || $found['address'] === null) {
            $contact = $this->contactLink($url, $html);
            $more = $contact !== null ? $this->get($contact, $fresh)['html'] : null;

            if ($more !== null) {
                $extra = $this->read($more, $host);

                foreach (['phone', 'email', 'address', 'country', 'legal_name', 'registration_number', 'tax_number', 'timezone', 'currency'] as $key) {
                    $found[$key] ??= $extra[$key];
                }
            }
        }

        $country = $found['country'] ?? Countries::fromHost($host) ?? Countries::fromPhone($found['phone']) ?? Countries::fromLocale($found['locale']);

        return [
            'reachable' => true,
            'name' => $found['name'],
            'legal_name' => $found['legal_name'],
            'phone' => $found['phone'],
            'email' => $found['email'],
            'address' => $found['address'],
            'country' => $country,
            'currency' => $found['currency'] ?? Countries::currency($country),
            'timezone' => $found['timezone'] ?? Countries::timezone($country, (string) $found['address']),
            'registration_number' => $found['registration_number'],
            'tax_number' => $found['tax_number'],
            'logo' => $this->logoCandidates($url, $html)[0] ?? null,
            'icon' => $this->iconCandidates($url, $html)[0] ?? null,
        ];
    }

    /**
     * Where the brand's own logo may be, the likeliest first: the logo the
     * site names for search engines, then pictures marked as the logo, the
     * one in the page's header before any other.
     *
     * @return list<string>
     */
    public function logoCandidates(string $url, string $html): array
    {
        $url = rtrim($url, '/');
        $host = mb_strtolower((string) parse_url($url, PHP_URL_HOST));
        $scored = [];

        $named = $this->text($this->organisation($this->nodes($html), $host)['logo'] ?? null);

        if ($named !== null) {
            $scored[] = ['url' => $this->absolute($url, $named), 'score' => 10];
        }

        // A picture counts when it says "logo" itself, or sits inside something that does (<a class="logo">).
        $wrapped = [];

        if (preg_match_all('~(?:class|id)=["\'][^"\']*(?:logo|navbar-brand|site-branding)[^"\']*["\'][^>]*>(?:\s*<(?:a|span|div|picture|source|figure)\b[^>]*>)*\s*(<img\b[^>]*>)~i', $html, $inside)) {
            $wrapped = $inside[1];
        }

        $headerEnds = stripos($html, '</header>');
        $top = $headerEnds !== false ? $headerEnds : (int) (strlen($html) * 0.3);

        if (preg_match_all('~<img\b[^>]*>~i', $html, $tags, PREG_OFFSET_CAPTURE)) {
            foreach ($tags[0] as [$tag, $position]) {
                $source = $this->imageSource($tag);

                if ($source === null || (stripos($tag, 'logo') === false && ! in_array($tag, $wrapped, true))) {
                    continue;
                }

                $score = 3 + ($position < $top ? 2 : 0) + (stripos(basename((string) parse_url($source, PHP_URL_PATH)), 'logo') !== false ? 1 : 0);

                if (preg_match(self::NOT_OUR_LOGO, $tag)) {
                    $score -= 6;
                }

                if (preg_match(self::FOR_DARK, basename((string) parse_url($source, PHP_URL_PATH)))) {
                    $score -= 2;
                }

                $scored[] = ['url' => $this->absolute($url, $source), 'score' => $score];
            }
        }

        $scored = array_values(array_filter($scored, fn (array $item): bool => $item['score'] > 0));
        usort($scored, fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return array_values(array_unique(array_column($scored, 'url')));
    }

    /**
     * Icon addresses in the page, largest first, then /favicon.ico.
     *
     * @return list<string>
     */
    public function iconCandidates(string $url, string $html): array
    {
        $url = rtrim($url, '/');
        $icons = [];

        if (preg_match_all('~<link\b[^>]*>~i', $html, $tags)) {
            foreach ($tags[0] as $tag) {
                if (! preg_match('~rel=["\']?([^"\'>]*icon[^"\'>]*)~i', $tag, $rel) || ! preg_match('~href=["\']?([^"\'\s>]+)~i', $tag, $href)) {
                    continue;
                }

                $size = preg_match('~sizes=["\']?(\d+)x\d+~i', $tag, $sizes) ? (int) $sizes[1] : 0;
                $apple = str_contains(mb_strtolower($rel[1]), 'apple') ? 180 : 0;

                $icons[] = ['url' => $this->absolute($url, html_entity_decode($href[1])), 'size' => max($size, $apple, 16)];
            }
        }

        usort($icons, fn (array $a, array $b): int => $b['size'] <=> $a['size']);

        return array_values(array_unique([...array_column($icons, 'url'), $url.'/favicon.ico']));
    }

    /** An address written in a page (absolute, "//host/x" or "/x") as a full one. */
    public function absolute(string $url, string $href): string
    {
        return match (true) {
            (bool) preg_match('~^https?://~i', $href) => $href,
            str_starts_with($href, '//') => 'https:'.$href,
            default => rtrim($url, '/').'/'.ltrim($href, '/'),
        };
    }

    public function http(): PendingRequest
    {
        return Http::timeout(8)->connectTimeout(4)->withUserAgent('Mozilla/5.0 (compatible; LiquifyCRM)');
    }

    // ------------------------------------------------------------------ reading

    /**
     * One page's worth of details, before the country fills in what is missing.
     *
     * @return array{name: ?string, legal_name: ?string, phone: ?string, email: ?string, address: ?string, country: ?string, currency: ?string, timezone: ?string, registration_number: ?string, tax_number: ?string, locale: ?string}
     */
    private function read(string $html, string $host): array
    {
        $nodes = $this->nodes($html);
        $company = $this->organisation($nodes, $host);
        $contact = $company['contactPoint'] ?? [];
        $contact = is_array($contact) ? (array_is_list($contact) ? (is_array($contact[0] ?? null) ? $contact[0] : []) : $contact) : [];

        $country = null;
        $address = $this->postal($company['address'] ?? null, $country) ?? $this->addressTag($html);

        $legal = $this->text($company['legalName'] ?? null);
        $other = $this->text($company['alternateName'] ?? null);

        // "UK Publishing House Ltd" as the other name is the company's name on paper.
        if ($legal === null && $other !== null && preg_match('~\b(ltd|limited|llc|llp|inc|plc|corp|corporation|gmbh|pty)\.?$~i', $other)) {
            $legal = $other;
        }

        $text = null;
        $registration = $this->registration($company);
        $tax = $this->text($company['vatID'] ?? null) ?? $this->text($company['taxID'] ?? null);

        if ($registration === null || $tax === null) {
            $text = $this->visibleText($html);
            $registration ??= $this->registrationInText($text);
            $tax ??= preg_match('~\bVAT\s*(?:reg(?:istration)?\.?\s*)?(?:no\.?|number|num\.?|#)\s*[:.]?\s*((?:GB)?\s?\d{3}\s?\d{4}\s?\d{2})\b~i', $text, $match) ? trim($match[1]) : null;
        }

        return [
            'name' => $this->name($html, $this->text($company['name'] ?? null)),
            'legal_name' => $legal !== null ? mb_substr($legal, 0, 255) : null,
            'phone' => $this->phone($this->text($company['telephone'] ?? null)) ?? $this->phone($this->text($contact['telephone'] ?? null)) ?? $this->phoneLink($html),
            'email' => $this->email($this->text($company['email'] ?? null)) ?? $this->email($this->text($contact['email'] ?? null)) ?? $this->emailLink($html, $host),
            'address' => $address,
            'country' => $country,
            'currency' => $this->currency($html),
            'timezone' => $this->timezone($company),
            'registration_number' => $registration !== null ? mb_substr($registration, 0, 60) : null,
            'tax_number' => $tax !== null ? mb_substr($tax, 0, 60) : null,
            'locale' => preg_match('~<html\b[^>]*\blang=["\']?([a-z]{2,3}[-_][a-z]{2})~i', $html, $match) || preg_match('~<meta\b[^>]*og:locale["\'][^>]*content=["\']([^"\']+)~i', $html, $match) ? $match[1] : null,
        ];
    }

    /**
     * The name a website gives itself: og:site_name, the company in its
     * structured data, application-name, then the page title without its
     * tagline ("River Books | Independent publisher" becomes "River Books").
     */
    private function name(string $html, ?string $company): ?string
    {
        $head = mb_substr($html, 0, 200000);
        $name = null;

        if (preg_match('~<meta\b[^>]*property=["\']og:site_name["\'][^>]*content=["\']([^"\']+)~i', $head, $match)
            || preg_match('~<meta\b[^>]*content=["\']([^"\']+)["\'][^>]*property=["\']og:site_name["\']~i', $head, $match)) {
            $name = $match[1];
        } elseif ($company !== null) {
            $name = $company;
        } elseif (preg_match('~<meta\b[^>]*name=["\']application-name["\'][^>]*content=["\']([^"\']+)~i', $head, $match)) {
            $name = $match[1];
        } elseif (preg_match('~<title[^>]*>([^<]+)</title>~i', $head, $match)) {
            // A page title usually carries a tagline after the name.
            $name = (string) (preg_split('~\s+[|\-–—:·»]\s+~u', trim(html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5)))[0] ?? '');
        }

        $name = $name !== null ? trim(html_entity_decode($name, ENT_QUOTES | ENT_HTML5)) : '';

        if ($name === '' || in_array(mb_strtolower($name), ['home', 'homepage', 'welcome'], true)) {
            return null;
        }

        return mb_substr($name, 0, 80);
    }

    /**
     * Every described thing in the page's structured data, nested ones too.
     *
     * @return list<array<string, mixed>>
     */
    private function nodes(string $html): array
    {
        $nodes = [];

        if (preg_match_all('~<script\b[^>]*application/ld\+json[^>]*>(.*?)</script>~is', $html, $blocks)) {
            foreach ($blocks[1] as $block) {
                $data = json_decode(trim($block), true);

                if (is_array($data)) {
                    $this->collect($data, $nodes, 0);
                }
            }
        }

        return $nodes;
    }

    /**
     * @param  array<mixed>  $data
     * @param  list<array<string, mixed>>  $nodes
     */
    private function collect(array $data, array &$nodes, int $depth): void
    {
        if ($depth > 6) {
            return;
        }

        if (isset($data['@type'])) {
            /** @var array<string, mixed> $data */
            $nodes[] = $data;
        }

        foreach ($data as $value) {
            if (is_array($value)) {
                $this->collect($value, $nodes, $depth + 1);
            }
        }
    }

    /**
     * The thing that describes the company itself: it names a way to reach
     * it, and is an organisation or business rather than a person or product.
     *
     * @param  list<array<string, mixed>>  $nodes
     * @return array<string, mixed>
     */
    private function organisation(array $nodes, string $host): array
    {
        $best = [];
        $bestScore = 0;

        foreach ($nodes as $node) {
            $types = implode(' ', array_filter((array) $node['@type'], is_string(...)));
            $score = count(array_intersect_key($node, array_flip(['address', 'telephone', 'logo', 'contactPoint', 'email', 'legalName'])));

            if ($score === 0 || preg_match('~Person|Product|Article|Posting|Review|Event|Recipe|Offer~i', $types)) {
                continue;
            }

            $score += preg_match('~Organization|Business|Corporation|Company|Store|Service|Publisher|Agency|Brand~i', $types) ? 3 : 0;
            $score += mb_strtolower((string) parse_url((string) $this->text($node['url'] ?? null), PHP_URL_HOST)) === $host ? 2 : 0;

            if ($score > $bestScore) {
                $best = $node;
                $bestScore = $score;
            }
        }

        return $best;
    }

    /** A value that may be plain text or a small described thing ({"@type": "ImageObject", "url": "..."}). */
    private function text(mixed $value): ?string
    {
        if (is_array($value)) {
            foreach (['url', 'contentUrl', 'name', '@value', 'value'] as $key) {
                if (isset($value[$key]) && ! is_array($value[$key])) {
                    return $this->text($value[$key]);
                }
            }

            return array_is_list($value) && $value !== [] ? $this->text($value[0]) : null;
        }

        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            return null;
        }

        $value = trim(html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5));

        return $value !== '' ? $value : null;
    }

    private function phone(?string $value): ?string
    {
        $value = trim((string) preg_replace('/\s+/u', ' ', rawurldecode((string) preg_replace('~^tel:~i', '', (string) $value))));
        $digits = strlen((string) preg_replace('/\D+/', '', $value));

        if ($digits < 7 || $digits > 15 || preg_match('/[^0-9+()\-. ]/', $value)) {
            return null;
        }

        return mb_substr($value, 0, 40);
    }

    /** The first number the page lets a visitor tap to call, written the way the page shows it. */
    private function phoneLink(string $html): ?string
    {
        if (! preg_match('~<a\b[^>]*href=["\']tel:([^"\']+)["\'][^>]*>(.*?)</a>~is', $html, $match)) {
            return null;
        }

        $dialled = $this->phone($match[1]);
        $shown = $this->phone(strip_tags($match[2]));

        // "+44 20 3048 2763" on the page reads better than "+442030482763" in the link.
        if ($dialled !== null && $shown !== null && substr((string) preg_replace('/\D+/', '', $shown), -7) === substr((string) preg_replace('/\D+/', '', $dialled), -7)) {
            return $shown;
        }

        return $dialled;
    }

    private function email(?string $value): ?string
    {
        $value = mb_strtolower(trim(rawurldecode(explode('?', (string) preg_replace('~^mailto:~i', '', (string) $value))[0])));

        if (filter_var($value, FILTER_VALIDATE_EMAIL) === false || preg_match('~@(example\.|sentry\.|.*wixpress\.|domain\.com$|email\.com$|yourdomain)~', $value)) {
            return null;
        }

        return mb_substr($value, 0, 255);
    }

    /** The address a visitor is asked to write to: one on the site's own domain before any other. */
    private function emailLink(string $html, string $host): ?string
    {
        $found = [];

        if (preg_match_all('~href=["\']mailto:([^"\']+)["\']~i', $html, $links)) {
            $found = $links[1];
        }

        // Cloudflare hides addresses from robots as hex: the first byte is the key to the rest.
        if (preg_match_all('~data-cfemail=["\']([0-9a-f]+)["\']~i', $html, $hidden)) {
            foreach ($hidden[1] as $hex) {
                $bytes = array_map('hexdec', str_split($hex, 2));
                $key = (int) array_shift($bytes);
                $found[] = implode('', array_map(fn (int|float $byte): string => chr(((int) $byte) ^ $key), $bytes));
            }
        }

        $emails = array_values(array_filter(array_map($this->email(...), $found)));
        $domain = (string) preg_replace('~^www\.~', '', $host);

        foreach ($emails as $email) {
            if ($domain !== '' && str_ends_with($email, '@'.$domain)) {
                return $email;
            }
        }

        return $emails[0] ?? null;
    }

    /**
     * A postal address the way it is written on an envelope, one part per
     * line. `$country` is set when the address names one.
     */
    private function postal(mixed $address, ?string &$country): ?string
    {
        if (is_array($address) && array_is_list($address)) {
            $address = $address[0] ?? null;
        }

        if (is_string($address)) {
            return $this->lines($address);
        }

        if (! is_array($address)) {
            return null;
        }

        $named = $this->text($address['addressCountry'] ?? null);
        $country = Countries::code($named);
        $street = $this->text($address['streetAddress'] ?? null);
        $city = $this->text($address['addressLocality'] ?? null);
        $region = $this->text($address['addressRegion'] ?? null);

        // In the United Kingdom the nation ("England") is not part of a written address.
        if ($country === 'GB' || in_array(mb_strtolower((string) $region), ['england', 'scotland', 'wales', 'northern ireland'], true)) {
            $region = null;
        }

        if ($street === null && $city === null) {
            return null;
        }

        $place = trim(implode(', ', array_filter([$city, $region])).' '.$this->text($address['postalCode'] ?? null));

        return $this->lines(implode("\n", array_filter([$street, $place, Countries::name($country) ?? $named])));
    }

    /** An <address> tag, without the phone and email many themes put in it. */
    private function addressTag(string $html): ?string
    {
        if (! preg_match('~<address\b[^>]*>(.*?)</address>~is', $html, $match)) {
            return null;
        }

        $text = strip_tags((string) preg_replace('~<br\s*/?>|</(p|div|li|span)>~i', "\n", $match[1]));
        $lines = array_filter(
            array_map(trim(...), explode("\n", html_entity_decode($text, ENT_QUOTES | ENT_HTML5))),
            fn (string $line): bool => $line !== '' && ! str_contains($line, '@') && $this->phone($line) === null,
        );

        $address = $this->lines(implode("\n", array_slice($lines, 0, 4)));

        // A street address has a number in it; anything else in the tag is a slogan or a link.
        return $address !== null && mb_strlen($address) >= 10 && preg_match('/\d/', $address) ? $address : null;
    }

    private function lines(string $text): ?string
    {
        $lines = array_filter(array_map(fn (string $line): string => trim((string) preg_replace('/\s+/u', ' ', $line), " ,\t"), explode("\n", $text)));

        return $lines === [] ? null : mb_substr(implode("\n", $lines), 0, 500);
    }

    /** The currency the site prices in, when it says so and LiquifyCRM records sales in it. */
    private function currency(string $html): ?string
    {
        if (preg_match_all('~"(?:priceCurrency|currenciesAccepted)"\s*:\s*"([A-Za-z]{3})"~', $html, $matches)) {
            foreach ($matches[1] as $code) {
                if (in_array(mb_strtoupper($code), (array) config('liquify.currencies'), true)) {
                    return mb_strtoupper($code);
                }
            }
        }

        return null;
    }

    /**
     * The time zone the site gives its opening hours in.
     *
     * @param  array<string, mixed>  $company
     */
    private function timezone(array $company): ?string
    {
        $hours = $company['openingHoursSpecification'] ?? null;
        $hours = is_array($hours) && array_is_list($hours) ? ($hours[0] ?? null) : $hours;
        $zone = is_array($hours) ? $this->text($hours['timeZone'] ?? null) : null;

        return $zone !== null && in_array($zone, DateTimeZone::listIdentifiers(), true) ? $zone : null;
    }

    /**
     * The company's registration number where the site names it as one
     * ({"propertyID": "UK Companies House", "value": "15941429"}).
     *
     * @param  array<string, mixed>  $company
     */
    private function registration(array $company): ?string
    {
        $identifiers = $company['identifier'] ?? null;

        if (! is_array($identifiers)) {
            return null;
        }

        foreach (array_is_list($identifiers) ? $identifiers : [$identifiers] as $identifier) {
            if (! is_array($identifier)) {
                continue;
            }

            $kind = $this->text($identifier['propertyID'] ?? null).' '.$this->text($identifier['name'] ?? null);
            $value = isset($identifier['value']) && ! is_array($identifier['value']) ? trim((string) $identifier['value']) : '';

            if ($value !== '' && preg_match('~compan|regist|crn|abn|acn~i', $kind)) {
                return $value;
            }
        }

        return null;
    }

    /** "Company No. 15941429", "Registered in England and Wales No. 12345678", "ABN 12 345 678 901". */
    private function registrationInText(string $text): ?string
    {
        if (preg_match('~\b(?:company|registration|registered)\s*(?:reg(?:istration)?\.?\s*)?(?:no\.?|number|num\.?|#)\s*[:.]?\s*([A-Z]{0,2}\d{6,8})\b~i', $text, $match)
            || preg_match('~\bregistered in [A-Za-z &]{4,40}?,?\s*(?:company\s*)?(?:no\.?|number|#)\s*[:.]?\s*([A-Z]{0,2}\d{6,8})\b~i', $text, $match)) {
            return mb_strtoupper($match[1]);
        }

        if (preg_match('~\bABN\s*[:.]?\s*(\d{2}\s?\d{3}\s?\d{3}\s?\d{3})\b~', $text, $match)) {
            return 'ABN '.$match[1];
        }

        return null;
    }

    private function visibleText(string $html): string
    {
        $html = (string) preg_replace('~<(script|style|noscript|svg)\b.*?</\1>~is', ' ', $html);

        return (string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5));
    }

    /** The picture's address, also when the page loads it late (data-src) or offers several sizes (srcset). */
    private function imageSource(string $tag): ?string
    {
        foreach (['src', 'data-src', 'data-lazy-src', 'data-original', 'srcset', 'data-srcset'] as $attribute) {
            if (preg_match('~\s'.$attribute.'=["\']([^"\']+)["\']~i', $tag, $match)) {
                $source = trim(explode(' ', trim(html_entity_decode($match[1])))[0], ', ');

                if ($source !== '' && ! str_starts_with($source, 'data:')) {
                    return $source;
                }
            }
        }

        return null;
    }

    /** The site's own contact page, when the homepage links to one. */
    private function contactLink(string $url, string $html): ?string
    {
        $host = mb_strtolower((string) parse_url($url, PHP_URL_HOST));

        if (! preg_match_all('~<a\b[^>]*href=["\']([^"\'#]*contact[^"\'#]*)["\']~i', $html, $links)) {
            return null;
        }

        foreach ($links[1] as $href) {
            if (preg_match('~^(mailto|tel|javascript):~i', $href)) {
                continue;
            }

            $address = $this->absolute($url, html_entity_decode($href));

            if (mb_strtolower((string) parse_url($address, PHP_URL_HOST)) === $host && rtrim($address, '/') !== $url) {
                return $address;
            }
        }

        return null;
    }

    // ----------------------------------------------------------------- fetching

    /** @return array{html: ?string, error: ?string} */
    private function get(string $address, bool $fresh): array
    {
        $key = 'brands:website:'.sha1($address);

        if (! $fresh) {
            $kept = Cache::get($key);
            $html = is_string($kept) ? $this->unpack($kept) : null;

            if ($html !== null) {
                return ['html' => $html, 'error' => null];
            }
        }

        try {
            $response = $this->http()->get($address);
        } catch (Throwable) {
            return ['html' => null, 'error' => 'The website could not be reached.'];
        }

        if (! $response->successful()) {
            return ['html' => null, 'error' => 'The website answered with error '.$response->status().'.'];
        }

        $html = $this->tidy($response->body());

        // Packed, so a page with odd characters is safe in the database cache.
        Cache::put($key, base64_encode((string) gzdeflate($html)), self::KEEP);

        return ['html' => $html, 'error' => null];
    }

    private function unpack(string $kept): ?string
    {
        $packed = base64_decode($kept, true);

        try {
            $html = $packed !== false ? gzinflate($packed) : false;
        } catch (Throwable) {
            return null;
        }

        return is_string($html) && $html !== '' ? $html : null;
    }

    /** Readable text, and both ends of a very long page: the head says who the site is, the foot how to reach it. */
    private function tidy(string $html): string
    {
        if (! mb_check_encoding($html, 'UTF-8')) {
            $html = mb_convert_encoding($html, 'UTF-8', 'Windows-1252');
        }

        if (strlen($html) > 700000) {
            $html = mb_strcut($html, 0, 400000, 'UTF-8').mb_strcut($html, strlen($html) - 300000, null, 'UTF-8');
        }

        return $html;
    }
}
