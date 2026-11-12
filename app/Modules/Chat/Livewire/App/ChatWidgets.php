<?php

declare(strict_types=1);

namespace App\Modules\Chat\Livewire\App;

use App\Modules\Auth\Models\User;
use App\Modules\Brands\Models\Brand;
use App\Modules\Chat\Models\ChatWidget;
use App\Modules\Chat\Support\LiveChat;
use App\Modules\Chat\Support\WebsiteLook;
use App\Modules\Core\Livewire\Concerns\WithConfirmation;
use App\Modules\Core\Livewire\Concerns\WithToasts;
use App\Modules\Core\Support\Audit;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Live chat settings of a brand (user request, 3 Nov 2026): its widgets, the one line a website carries, the
 * websites a widget may run on, how it looks and what it asks the visitor first. For Admins and the brand's
 * Managers.
 */
#[Layout('components.layouts.app')]
#[Title('Live chat')]
final class ChatWidgets extends Component
{
    use AuthorizesRequests;
    use WithConfirmation;
    use WithToasts;

    public Brand $brand;

    public bool $showForm = false;

    #[Locked]
    public ?int $editing = null;

    public string $name = '';

    public string $domains = '';

    public string $color = '#f97316';

    public string $position = 'right';

    public string $title = '';

    public string $welcome = '';

    public string $offline = '';

    public string $consent = '';

    /** The largest file a visitor may send, in megabytes. */
    public int $maxMb = 10;

    /** Whether the team sees who is on the website (Live visitors). */
    public bool $track = true;

    public bool $askFirst = false;

    /** @var array<string, bool> */
    public array $fields = ['name' => true, 'email' => true, 'phone' => false, 'message' => false];

    public bool $agentsSeeVisitors = true;

    // ---- the customiser (user request, 12 Nov 2026) ----------------------------------------------------------

    /** The part of the customiser in view: look, window, words, behaviour or setup. */
    public string $tab = 'look';

    public string $style = 'pill';

    public string $accent = '';

    public string $label = '';

    public string $onlineSub = '';

    public string $offlineSub = '';

    public string $replyTime = '';

    public string $theme = 'light';

    public string $header = 'solid';

    public string $corners = 'standard';

    public string $size = 'standard';

    public int $offsetX = 20;

    public int $offsetY = 20;

    public string $showOn = 'all';

    /** Seconds before the greeting shows by the button; 0 is never. */
    public int $greetAfter = 0;

    public bool $sound = true;

    public bool $logo = false;

    /** The button on phones; empty is the same as on computers. */
    public string $stylePhone = '';

    public string $icon = 'chat';

    public string $motion = 'none';

    /** A few words above the button, until the visitor opens or dismisses them. */
    public string $teaser = '';

    public string $awayMode = 'form';

    public bool $grabber = false;

    public string $grabberArt = 'wave';

    public string $grabberText = '';

    public string $grabberYes = '';

    public string $grabberNo = '';

    /** Whether visitors may give the chat a thumb up or down. */
    public bool $rating = true;

    /**
     * Colours read from the website, offered as swatches.
     *
     * @var list<string>
     */
    public array $palette = [];

    public ?string $paletteFrom = null;

    public bool $active = true;

    public function mount(Brand $brand): void
    {
        $this->brand = $brand;
        $this->authorize('viewAny', ChatWidget::class);
    }

    public function create(): void
    {
        $this->authorize('create', ChatWidget::class);

        $this->resetErrorBag();
        $this->reset('editing', 'domains', 'title', 'welcome', 'offline', 'consent', 'maxMb', 'track', 'askFirst', 'agentsSeeVisitors', 'active', 'position',
            'style', 'accent', 'label', 'onlineSub', 'offlineSub', 'replyTime', 'theme', 'header', 'corners', 'size', 'offsetX', 'offsetY', 'showOn', 'greetAfter', 'sound', 'logo', 'palette', 'paletteFrom',
            'stylePhone', 'icon', 'motion', 'teaser', 'awayMode', 'grabber', 'grabberArt', 'grabberText', 'grabberYes', 'grabberNo', 'rating');
        $this->name = $this->brand->name;
        $this->color = $this->brand->primaryColor() ?? ChatWidget::DEFAULT_APPEARANCE['color'];
        // The brand's own website is the likely first one.
        $host = (string) (parse_url((string) $this->brand->website, PHP_URL_HOST) ?? '');
        $this->domains = preg_replace('/^www\./', '', $host) ?? $host;
        $this->tab = 'setup';
        $this->fields = ['name' => true, 'email' => true, 'phone' => false, 'message' => false];
        $this->showForm = true;
    }

    public function edit(int $widgetId): void
    {
        $widget = ChatWidget::query()->findOrFail($widgetId);
        $this->authorize('update', $widget);

        $this->resetErrorBag();
        $this->editing = $widgetId;
        $this->name = $widget->name;
        $this->domains = implode("\n", $widget->allowed_domains ?? []);
        $this->color = $widget->look('color');
        $this->position = $widget->look('position');
        // Texts the brand never changed stay empty, so the default shows as a hint and follows later improvements.
        $this->title = (string) (($widget->appearance ?? [])['title'] ?? '');
        $this->welcome = (string) (($widget->appearance ?? [])['welcome'] ?? '');
        $this->offline = (string) (($widget->appearance ?? [])['offline'] ?? '');
        $this->consent = (string) (($widget->appearance ?? [])['consent'] ?? '');
        $this->maxMb = (int) (($widget->appearance ?? [])['max_upload_mb'] ?? config('liquify.chat.max_upload_mb', 10));
        $this->track = $widget->tracks();
        $this->askFirst = $widget->asksFirst();
        $this->fields = [];
        foreach (array_keys(ChatWidget::PRECHAT_FIELDS) as $field) {
            $this->fields[$field] = $widget->asks($field);
        }
        $this->agentsSeeVisitors = $widget->agents_see_visitors;
        $this->active = $widget->is_active;

        $look = $widget->appearance ?? [];
        $this->style = $widget->choice('style');
        $this->accent = (string) ($look['accent'] ?? '');
        $this->label = (string) ($look['label'] ?? '');
        $this->onlineSub = (string) ($look['online_sub'] ?? '');
        $this->offlineSub = (string) ($look['offline_sub'] ?? '');
        $this->replyTime = (string) ($look['reply_time'] ?? '');
        $this->theme = $widget->choice('theme');
        $this->header = $widget->choice('header');
        $this->corners = $widget->choice('corners');
        $this->size = $widget->choice('size');
        $this->offsetX = $widget->number('offset_x', 0, 160);
        $this->offsetY = $widget->number('offset_y', 0, 160);
        $this->showOn = $widget->choice('show_on');
        $this->greetAfter = $widget->number('greet_after', 0, 120);
        $this->sound = ($look['sound'] ?? '1') !== '0';
        $this->logo = ($look['logo'] ?? '0') === '1';
        $this->stylePhone = $widget->choice('style_phone');
        $this->icon = $widget->choice('icon');
        $this->motion = $widget->choice('effect');
        $this->teaser = (string) ($look['teaser'] ?? '');
        $this->awayMode = $widget->choice('away_mode');
        $this->grabber = ($look['grabber'] ?? '0') === '1';
        $this->grabberArt = $widget->choice('grabber_art');
        $this->grabberText = (string) ($look['grabber_text'] ?? '');
        $this->grabberYes = (string) ($look['grabber_yes'] ?? '');
        $this->grabberNo = (string) ($look['grabber_no'] ?? '');
        $this->rating = ($look['rating'] ?? '1') !== '0';
        $this->palette = [];
        $this->paletteFrom = null;
        $this->tab = 'look';
        $this->showForm = true;
    }

    /**
     * Reads the colours of the chat box's first website and takes its main colour and accent; the other colours it
     * found are offered as swatches.
     */
    public function matchWebsite(WebsiteLook $look): void
    {
        if ($this->editing !== null) {
            $this->authorize('update', ChatWidget::query()->findOrFail($this->editing));
        } else {
            $this->authorize('create', ChatWidget::class);
        }

        $domain = ChatWidget::parseDomains($this->domains)['domains'][0] ?? null;

        if ($domain === null) {
            $this->tab = 'setup';
            $this->addError('domains', 'List the website first, so we can read its colours.');

            return;
        }

        $found = $look->read($domain);

        if ($found['color'] === null) {
            $this->toastWarning('No colours found', $found['error']);

            return;
        }

        $this->color = $found['color'];
        $this->accent = (string) $found['accent'];
        $this->palette = $found['palette'];
        $this->paletteFrom = $domain;
        $this->toastSuccess('Colours taken from '.$domain, 'Pick another swatch if you prefer one.');
    }

    public function save(): void
    {
        $widget = $this->editing !== null ? ChatWidget::query()->findOrFail($this->editing) : null;

        if ($widget !== null) {
            $this->authorize('update', $widget);
        } else {
            $this->authorize('create', ChatWidget::class);
        }

        try {
            $this->validateDesign();
        } catch (ValidationException $exception) {
            // Show the part of the customiser where the first problem is.
            $first = (string) array_key_first($exception->errors());
            $this->tab = match (true) {
                in_array($first, ['name', 'domains'], true) => 'setup',
                in_array($first, ['title', 'welcome', 'offline', 'consent', 'label', 'onlineSub', 'offlineSub', 'replyTime', 'teaser'], true) => 'words',
                in_array($first, ['maxMb', 'showOn', 'greetAfter', 'motion', 'awayMode', 'grabberArt', 'grabberText', 'grabberYes', 'grabberNo'], true) => 'behaviour',
                in_array($first, ['theme', 'header', 'corners', 'size'], true) => 'window',
                default => 'look',
            };

            throw $exception;
        }

        $this->saveValidated($widget);
    }

    private function validateDesign(): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'domains' => ['required', 'string', 'max:2000'],
            'color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'position' => ['required', 'in:left,right'],
            'title' => ['nullable', 'string', 'max:60'],
            'welcome' => ['nullable', 'string', 'max:300'],
            'offline' => ['nullable', 'string', 'max:300'],
            'consent' => ['nullable', 'string', 'max:300'],
            'maxMb' => ['required', 'integer', 'min:1', 'max:25'],
            'accent' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'style' => ['required', Rule::in(array_keys(ChatWidget::CHOICES['style']))],
            'theme' => ['required', Rule::in(array_keys(ChatWidget::CHOICES['theme']))],
            'header' => ['required', Rule::in(array_keys(ChatWidget::CHOICES['header']))],
            'corners' => ['required', Rule::in(array_keys(ChatWidget::CHOICES['corners']))],
            'size' => ['required', Rule::in(array_keys(ChatWidget::CHOICES['size']))],
            'showOn' => ['required', Rule::in(array_keys(ChatWidget::CHOICES['show_on']))],
            'offsetX' => ['required', 'integer', 'min:0', 'max:160'],
            'offsetY' => ['required', 'integer', 'min:0', 'max:160'],
            'greetAfter' => ['required', 'integer', 'min:0', 'max:120'],
            'label' => ['nullable', 'string', 'max:40'],
            'onlineSub' => ['nullable', 'string', 'max:60'],
            'offlineSub' => ['nullable', 'string', 'max:60'],
            'replyTime' => ['nullable', 'string', 'max:80'],
            'stylePhone' => ['nullable', Rule::in(array_keys(ChatWidget::CHOICES['style_phone']))],
            'icon' => ['required', Rule::in(array_keys(ChatWidget::CHOICES['icon']))],
            'motion' => ['required', Rule::in(array_keys(ChatWidget::CHOICES['effect']))],
            'awayMode' => ['required', Rule::in(array_keys(ChatWidget::CHOICES['away_mode']))],
            'teaser' => ['nullable', 'string', 'max:40'],
            'grabberArt' => ['required', Rule::in(array_keys(ChatWidget::CHOICES['grabber_art']))],
            'grabberText' => ['nullable', 'string', 'max:120'],
            'grabberYes' => ['nullable', 'string', 'max:24'],
            'grabberNo' => ['nullable', 'string', 'max:24'],
        ], [
            'domains.required' => 'List the website this chat runs on, for example example.com.',
            'color.regex' => 'Choose a colour.',
            'maxMb.max' => 'The largest file can be 25 MB.',
            'accent.regex' => 'Choose an accent colour.',
        ], ['domains' => 'websites', 'maxMb' => 'largest file', 'onlineSub' => 'line when you are here', 'offlineSub' => 'line when nobody is here', 'replyTime' => 'reply time', 'label' => 'button words']);
    }

    private function saveValidated(?ChatWidget $widget): void
    {
        $parsed = ChatWidget::parseDomains($this->domains);

        if ($parsed['invalid'] !== [] || $parsed['domains'] === []) {
            $this->tab = 'setup';
        }

        if ($parsed['invalid'] !== []) {
            $this->addError('domains', '"'.$parsed['invalid'][0].'" is not a website address. Write one a line, like example.com.');

            return;
        }

        if ($parsed['domains'] === []) {
            $this->addError('domains', 'List the website this chat runs on, for example example.com.');

            return;
        }

        $fields = [];
        foreach (array_keys(ChatWidget::PRECHAT_FIELDS) as $field) {
            $fields[$field] = (bool) ($this->fields[$field] ?? false);
        }

        $values = [
            'name' => trim($this->name),
            'allowed_domains' => $parsed['domains'],
            'appearance' => [
                'color' => strtolower($this->color),
                'position' => $this->position,
                'title' => trim($this->title),
                'welcome' => trim($this->welcome),
                'offline' => trim($this->offline),
                'consent' => trim($this->consent),
                'max_upload_mb' => (string) $this->maxMb,
                'track_visitors' => $this->track ? '1' : '0',
                'style' => $this->style,
                'accent' => strtolower($this->accent),
                'label' => trim($this->label),
                'online_sub' => trim($this->onlineSub),
                'offline_sub' => trim($this->offlineSub),
                'reply_time' => trim($this->replyTime),
                'theme' => $this->theme,
                'header' => $this->header,
                'corners' => $this->corners,
                'size' => $this->size,
                'offset_x' => (string) $this->offsetX,
                'offset_y' => (string) $this->offsetY,
                'show_on' => $this->showOn,
                'greet_after' => (string) $this->greetAfter,
                'sound' => $this->sound ? '1' : '0',
                'logo' => $this->logo ? '1' : '0',
                'style_phone' => $this->stylePhone,
                'icon' => $this->icon,
                'effect' => $this->motion,
                'teaser' => trim($this->teaser),
                'away_mode' => $this->awayMode,
                'grabber' => $this->grabber ? '1' : '0',
                'grabber_art' => $this->grabberArt,
                'grabber_text' => trim($this->grabberText),
                'grabber_yes' => trim($this->grabberYes),
                'grabber_no' => trim($this->grabberNo),
                'rating' => $this->rating ? '1' : '0',
            ],
            'prechat' => ['enabled' => $this->askFirst, 'fields' => $fields],
            'agents_see_visitors' => $this->agentsSeeVisitors,
            'is_active' => $this->active,
        ];

        $created = $widget === null;

        if ($widget !== null) {
            $widget->update($values);
            Audit::log('chat.widget_updated', $widget, ['name' => $widget->name], 'Live chat changed: '.$widget->name, brand: $this->brand, causer: $this->user(), logName: 'chat');
        } else {
            $widget = ChatWidget::query()->create([...$values, 'brand_id' => $this->brand->getKey(), 'widget_key' => ChatWidget::newKey(), 'created_by' => $this->user()->getKey()]);
            Audit::log('chat.widget_created', $widget, ['name' => $widget->name], 'Live chat added: '.$widget->name, brand: $this->brand, causer: $this->user(), logName: 'chat');
        }

        $this->showForm = false;
        $this->reset('editing');
        $this->toastSuccess($created ? 'Live chat added' : 'Live chat saved');
    }

    public function toggle(int $widgetId): void
    {
        $widget = ChatWidget::query()->findOrFail($widgetId);
        $this->authorize('update', $widget);

        $widget->update(['is_active' => ! $widget->is_active]);
        Audit::log($widget->is_active ? 'chat.widget_on' : 'chat.widget_off', $widget, ['name' => $widget->name], 'Live chat '.($widget->is_active ? 'switched on' : 'switched off').': '.$widget->name, brand: $this->brand, causer: $this->user(), logName: 'chat');
        $this->toastSuccess($widget->is_active ? 'Live chat is on' : 'Live chat is off', $widget->is_active ? null : 'The chat disappears from the website. Chats already started stay under Live chat › Chats.');
    }

    public function confirmRemove(int $widgetId): void
    {
        $widget = ChatWidget::query()->findOrFail($widgetId);
        $this->authorize('delete', $widget);

        $this->askConfirmation('remove', [$widgetId], 'Remove '.$widget->name.'?', 'The chat disappears from the website for good and the line on the website stops working. Chats already started stay under Live chat › Chats.', 'Remove', 'danger');
    }

    public function remove(int $widgetId): void
    {
        $widget = ChatWidget::query()->findOrFail($widgetId);
        $this->authorize('delete', $widget);

        $widget->delete();
        Audit::log('chat.widget_removed', null, ['widget_id' => $widgetId, 'name' => $widget->name], 'Live chat removed: '.$widget->name, brand: $this->brand, causer: $this->user(), logName: 'chat');
        $this->toastSuccess('Live chat removed');
    }

    public function render(): View
    {
        return view('chat::livewire.app.chat-widgets', [
            'widgets' => ChatWidget::query()->orderBy('name')->get(),
            'defaults' => ChatWidget::DEFAULT_APPEARANCE,
            'choices' => ChatWidget::CHOICES,
            'proxyReady' => LiveChat::proxyReady(),
            'logoUrl' => $this->brand->avatarUrl(),
            'prechatFields' => ChatWidget::PRECHAT_FIELDS,
        ]);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
