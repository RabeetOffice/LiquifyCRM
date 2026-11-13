<?php

declare(strict_types=1);

namespace App\Modules\Chat\Models;

use App\Modules\Auth\Models\User;
use App\Modules\Core\Concerns\BelongsToBrand;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A brand's live chat: the key its websites carry in one script line, the websites it may run on, how it
 * looks and what it asks before the chat starts. A brand with several websites lists them all on one widget.
 *
 * @property int $id
 * @property int $brand_id
 * @property string $name
 * @property string $widget_key
 * @property list<string>|null $allowed_domains
 * @property array<string, string>|null $appearance
 * @property array<string, mixed>|null $prechat
 * @property bool $agents_see_visitors
 * @property bool $is_active
 * @property string|null $proxy_secret
 * @property int|null $created_by
 * @property CarbonImmutable|null $created_at
 * @property-read User|null $creator
 */
class ChatWidget extends Model
{
    use BelongsToBrand;

    public const DEFAULT_APPEARANCE = [
        'color' => '#f97316',
        'position' => 'right',
        'title' => 'Chat with us',
        'welcome' => 'Hi! How can we help you today?',
        'offline' => 'We are away right now. Leave your details and we will get back to you.',
        'consent' => 'Your messages are kept so our team can reply. We do not share them.',
        // The customiser (12 Nov 2026). An older chat box keeps the round button it had.
        'style' => 'bubble',
        'accent' => '',
        'label' => 'Live chat',
        'online_sub' => 'We’re online, say hi',
        'offline_sub' => 'Leave us a message',
        'reply_time' => 'We usually reply in a few minutes',
        'theme' => 'light',
        'header' => 'solid',
        'corners' => 'standard',
        'size' => 'standard',
        'offset_x' => '20',
        'offset_y' => '20',
        'show_on' => 'all',
        'greet_after' => '0',
        'sound' => '1',
        'logo' => '0',
        // 1.52.0: a button of its own on phones, the icon, a moving touch, a teaser above the button, and what happens away.
        'style_phone' => '',
        'icon' => 'chat',
        'effect' => 'none',
        'teaser' => '',
        'away_mode' => 'form',
        // An attention grabber (a card with a picture, a question and two answers) and the visitor's thumbs up or down.
        'grabber' => '0',
        'grabber_art' => 'wave',
        'grabber_text' => 'Hello 👋 Do you have any questions?',
        'grabber_yes' => 'Yes, I do',
        'grabber_no' => 'Just browsing',
        'rating' => '1',
        // Voice calls from the chat box (1.58.0): a phone button in its header while somebody of the team is here.
        'call' => '1',
    ];

    /** The choices of the customiser, each with the words the screen shows. */
    public const CHOICES = [
        'style' => ['bubble' => 'Round button', 'pill' => 'Pill with words', 'glass' => 'Frosted glass', 'card' => 'Light card', 'square' => 'Rounded square', 'outline' => 'Outline pill', 'bar' => 'Bar at the bottom', 'tab' => 'Side tab'],
        'style_phone' => ['' => 'Same as on computers', 'bubble' => 'Round button', 'pill' => 'Pill with words', 'glass' => 'Frosted glass', 'card' => 'Light card', 'square' => 'Rounded square', 'outline' => 'Outline pill', 'bar' => 'Bar at the bottom', 'tab' => 'Side tab'],
        'icon' => ['chat' => 'Chat bubble', 'chats' => 'Two bubbles', 'question' => 'Question', 'sparkles' => 'Sparkles', 'hand' => 'Wave', 'envelope' => 'Envelope'],
        'effect' => ['none' => 'Still', 'pulse' => 'Pulse', 'bounce' => 'Bounce', 'wiggle' => 'Wiggle'],
        'away_mode' => ['form' => 'Show a form for a message', 'hide' => 'Hide the chat'],
        'grabber_art' => ['wave' => '👋', 'chat' => '💬', 'book' => '📚', 'sparkles' => '✨', 'gift' => '🎁', 'none' => ''],
        'theme' => ['light' => 'Light', 'dark' => 'Dark', 'auto' => 'Follow the visitor'],
        'header' => ['solid' => 'Solid', 'gradient' => 'Gradient', 'glass' => 'Glass'],
        'corners' => ['round' => 'Round', 'standard' => 'Soft', 'square' => 'Crisp'],
        'size' => ['compact' => 'Compact', 'standard' => 'Standard', 'large' => 'Large'],
        'show_on' => ['all' => 'Computers and phones', 'desktop' => 'Computers only', 'phone' => 'Phones only'],
    ];

    /** Window width and corner radius, in pixels, for each choice. */
    public const SIZES = ['compact' => 340, 'standard' => 380, 'large' => 420];

    public const RADII = ['round' => 24, 'standard' => 16, 'square' => 8];

    /** What the visitor may be asked before the chat starts. Name and email or phone are what make a lead. */
    public const PRECHAT_FIELDS = [
        'name' => 'Name',
        'email' => 'Email',
        'phone' => 'Phone number',
        'message' => 'How can we help?',
    ];

    protected $fillable = ['brand_id', 'name', 'widget_key', 'allowed_domains', 'appearance', 'prechat', 'agents_see_visitors', 'is_active', 'created_by'];

    protected function casts(): array
    {
        return [
            'allowed_domains' => 'array',
            'appearance' => 'array',
            'prechat' => 'array',
            'agents_see_visitors' => 'boolean',
            'is_active' => 'boolean',
            'proxy_secret' => 'encrypted',
        ];
    }

    /** A key nobody has: "wk_" and 24 letters and digits. */
    public static function newKey(): string
    {
        do {
            $key = 'wk_'.Str::random(24);
        } while (self::query()->withoutGlobalScopes()->where('widget_key', $key)->exists());

        return $key;
    }

    /** Never in an array or JSON of the widget. */
    protected $hidden = ['proxy_secret'];

    /** The secret liquify-chat.php signs with: made the first time it is asked for. */
    public function proxySecret(): string
    {
        if (! is_string($this->proxy_secret) || $this->proxy_secret === '') {
            $this->forceFill(['proxy_secret' => Str::random(48)])->save();
        }

        return (string) $this->proxy_secret;
    }

    /** liquify-chat.php for this widget, with LiquifyCRM's address, the widget's key and its secret written in. */
    public function proxyFile(): string
    {
        return strtr((string) file_get_contents(app_path('Modules/Chat/resources/stubs/liquify-chat.php.stub')), [
            '{{NAME}}' => str_replace(['*/', "\n", "\r"], '', $this->name),
            '{{CRM}}' => addcslashes(rtrim(url('/'), '/'), "'\\"),
            '{{KEY}}' => addcslashes($this->widget_key, "'\\"),
            '{{SECRET}}' => addcslashes($this->proxySecret(), "'\\"),
        ]);
    }

    /** The line for a website that carries liquify-chat.php in its root. */
    public function proxySnippet(): string
    {
        return '<script src="/liquify-chat.php" data-key="'.$this->widget_key.'" async></script>';
    }

    /** The one line the website's footer carries. */
    public function snippet(): string
    {
        return '<script src="'.url('/chat/widget.js').'" data-key="'.$this->widget_key.'" async></script>';
    }

    public function look(string $key): string
    {
        $value = ($this->appearance ?? [])[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : self::DEFAULT_APPEARANCE[$key];
    }

    /** Text on the widget's colour: black or white, whichever reads better. */
    public function onColor(): string
    {
        return self::textOn($this->look('color'));
    }

    /** The accent (the launcher's icon circle, the send button): the main colour when none was chosen. */
    public function accent(): string
    {
        $accent = $this->look('accent');

        return preg_match('/^#[0-9a-f]{6}$/i', $accent) === 1 ? strtolower($accent) : $this->look('color');
    }

    /** One of the customiser's choices, never a value outside the list. */
    public function choice(string $key): string
    {
        $value = $this->look($key);

        return array_key_exists($value, self::CHOICES[$key]) ? $value : self::DEFAULT_APPEARANCE[$key];
    }

    /** A number of the customiser, kept between its bounds. */
    public function number(string $key, int $min, int $max): int
    {
        return max($min, min($max, (int) $this->look($key)));
    }

    /**
     * Everything the launcher and the chat box draw themselves with. Colours are checked hex codes and the rest come from
     * fixed lists or bounded numbers, because they go into the website's page as styles; words are set as text only.
     *
     * @return array<string, string|int|bool>
     */
    public function design(): array
    {
        $color = preg_match('/^#[0-9a-f]{6}$/i', $this->look('color')) === 1 ? strtolower($this->look('color')) : self::DEFAULT_APPEARANCE['color'];
        $accent = $this->accent();
        $accent = preg_match('/^#[0-9a-f]{6}$/i', $accent) === 1 ? $accent : $color;

        return [
            'color' => $color,
            'on' => self::textOn($color),
            'accent' => $accent,
            'on_accent' => self::textOn($accent),
            'position' => $this->look('position') === 'left' ? 'left' : 'right',
            'style' => $this->choice('style'),
            'theme' => $this->choice('theme'),
            'header' => $this->choice('header'),
            'corners' => $this->choice('corners'),
            'radius' => self::RADII[$this->choice('corners')],
            'size' => $this->choice('size'),
            'width' => self::SIZES[$this->choice('size')],
            'offset_x' => $this->number('offset_x', 0, 160),
            'offset_y' => $this->number('offset_y', 0, 160),
            'show_on' => $this->choice('show_on'),
            'style_phone' => $this->choice('style_phone'),
            'icon' => $this->choice('icon'),
            'effect' => $this->choice('effect'),
            'away_mode' => $this->choice('away_mode'),
            'teaser' => $this->look('teaser'),
            'grabber' => $this->look('grabber') === '1',
            'grabber_art' => self::CHOICES['grabber_art'][$this->choice('grabber_art')],
            'grabber_text' => $this->look('grabber_text'),
            'grabber_yes' => $this->look('grabber_yes'),
            'grabber_no' => $this->look('grabber_no'),
            'rating' => $this->look('rating') !== '0',
            'call' => $this->look('call') !== '0',
            'greet_after' => $this->number('greet_after', 0, 120),
            'sound' => $this->look('sound') !== '0',
            'label' => $this->look('label'),
            'online_sub' => $this->look('online_sub'),
            'offline_sub' => $this->look('offline_sub'),
            'reply_time' => $this->look('reply_time'),
        ];
    }

    /** Black or white, whichever reads better on this colour. */
    public static function textOn(string $color): string
    {
        $hex = ltrim($color, '#');

        if (preg_match('/^[0-9a-f]{6}$/i', $hex) !== 1) {
            return '#ffffff';
        }

        $luma = (0.299 * hexdec(substr($hex, 0, 2)) + 0.587 * hexdec(substr($hex, 2, 2)) + 0.114 * hexdec(substr($hex, 4, 2))) / 255;

        return $luma > 0.62 ? '#111111' : '#ffffff';
    }

    /** Whether the team sees who is on the website (Live visitors). On unless the brand switched it off. */
    public function tracks(): bool
    {
        return (($this->appearance ?? [])['track_visitors'] ?? '1') !== '0';
    }

    /** Whether the widget asks for this field before the chat starts. */
    public function asks(string $field): bool
    {
        return (bool) (($this->prechat['fields'] ?? [])[$field] ?? false);
    }

    public function asksFirst(): bool
    {
        return (bool) ($this->prechat['enabled'] ?? false);
    }

    /**
     * Whether a page at this origin ("https://www.example.com") may run the widget: the host is one of the
     * listed websites or a subdomain of one. A widget with no website listed runs nowhere.
     */
    public function allowsOrigin(string $origin): bool
    {
        return $this->originFor($origin) !== null;
    }

    /**
     * The website's origin as the browser names it (scheme, host and port, nothing else) when it is one of the widget's
     * websites; null otherwise. Only https: a page on plain http can be changed on the way, so the chat box is not shown
     * there. A copy of a website on this computer (localhost) may use http. What the browser or the launcher sent is never
     * echoed back as it came (hardening, 12 Nov 2026).
     */
    public function originFor(string $origin): ?string
    {
        if (mb_strlen($origin) > 300 || preg_match('/[\s"\'<>\\\\]/', $origin) === 1) {
            return null;
        }

        $parts = parse_url($origin);
        $host = is_array($parts) && is_string($parts['host'] ?? null) ? strtolower($parts['host']) : '';
        $scheme = is_array($parts) && is_string($parts['scheme'] ?? null) ? strtolower($parts['scheme']) : '';

        if ($host === '' || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        $local = in_array($host, ['localhost', '127.0.0.1'], true) || str_ends_with($host, '.localhost');

        if ($scheme !== 'https' && ! ($scheme === 'http' && $local)) {
            return null;
        }

        foreach ($this->allowed_domains ?? [] as $domain) {
            if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                return $scheme.'://'.$host.(isset($parts['port']) ? ':'.(int) $parts['port'] : '');
            }
        }

        return null;
    }

    /**
     * Websites as people write them (one a line or separated by commas, with https:// and paths and www.)
     * turned into plain host names, without duplicates. A line that is not a host name is returned in
     * `invalid`.
     *
     * @return array{domains: list<string>, invalid: list<string>}
     */
    public static function parseDomains(string $text): array
    {
        $domains = [];
        $invalid = [];

        foreach (preg_split('/[\s,;]+/', trim($text)) ?: [] as $entry) {
            if ($entry === '') {
                continue;
            }

            $host = strtolower((string) (parse_url(str_contains($entry, '://') ? $entry : 'https://'.$entry, PHP_URL_HOST) ?? ''));
            $host = preg_replace('/^www\./', '', $host) ?? $host;

            if (preg_match('/^(?=.{4,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/', $host) === 1) {
                $domains[$host] = $host;
            } else {
                $invalid[] = $entry;
            }
        }

        return ['domains' => array_values($domains), 'invalid' => $invalid];
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<ChatVisitor, $this> */
    public function visitors(): HasMany
    {
        return $this->hasMany(ChatVisitor::class);
    }
}
