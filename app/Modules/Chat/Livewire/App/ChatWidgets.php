<?php

declare(strict_types=1);

namespace App\Modules\Chat\Livewire\App;

use App\Modules\Auth\Models\User;
use App\Modules\Brands\Models\Brand;
use App\Modules\Chat\Models\ChatWidget;
use App\Modules\Core\Livewire\Concerns\WithConfirmation;
use App\Modules\Core\Livewire\Concerns\WithToasts;
use App\Modules\Core\Support\Audit;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
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

    public bool $askFirst = false;

    /** @var array<string, bool> */
    public array $fields = ['name' => true, 'email' => true, 'phone' => false, 'message' => false];

    public bool $agentsSeeVisitors = true;

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
        $this->reset('editing', 'domains', 'title', 'welcome', 'offline', 'consent', 'maxMb', 'askFirst', 'agentsSeeVisitors', 'active', 'position');
        $this->name = $this->brand->name;
        $this->color = $this->brand->primaryColor() ?? ChatWidget::DEFAULT_APPEARANCE['color'];
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
        $this->askFirst = $widget->asksFirst();
        $this->fields = [];
        foreach (array_keys(ChatWidget::PRECHAT_FIELDS) as $field) {
            $this->fields[$field] = $widget->asks($field);
        }
        $this->agentsSeeVisitors = $widget->agents_see_visitors;
        $this->active = $widget->is_active;
        $this->showForm = true;
    }

    public function save(): void
    {
        $widget = $this->editing !== null ? ChatWidget::query()->findOrFail($this->editing) : null;

        if ($widget !== null) {
            $this->authorize('update', $widget);
        } else {
            $this->authorize('create', ChatWidget::class);
        }

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
        ], [
            'domains.required' => 'List the website this chat runs on, for example example.com.',
            'color.regex' => 'Choose a colour.',
            'maxMb.max' => 'The largest file can be 25 MB.',
        ], ['domains' => 'websites', 'maxMb' => 'largest file']);

        $parsed = ChatWidget::parseDomains($this->domains);

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
        $this->toastSuccess($widget->is_active ? 'Live chat is on' : 'Live chat is off', $widget->is_active ? null : 'The chat disappears from the website. Chats already started stay in the Inbox.');
    }

    public function confirmRemove(int $widgetId): void
    {
        $widget = ChatWidget::query()->findOrFail($widgetId);
        $this->authorize('delete', $widget);

        $this->askConfirmation('remove', [$widgetId], 'Remove '.$widget->name.'?', 'The chat disappears from the website for good and the line on the website stops working. Chats already started stay in the Inbox.', 'Remove', 'danger');
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
