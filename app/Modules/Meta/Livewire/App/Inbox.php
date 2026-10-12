<?php

declare(strict_types=1);

namespace App\Modules\Meta\Livewire\App;

use App\Modules\Brands\Models\Brand;
use App\Modules\Leads\Support\ContactVisibility;
use App\Modules\Meta\Models\Conversation;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Inbox: Messenger threads of the leads a person may see (Admins all,
 * Managers their departments, Agents their own), newest first, in three
 * panes: the chats, the open thread, and the lead it belongs to.
 */
#[Layout('components.layouts.app')]
#[Title('Inbox')]
final class Inbox extends Component
{
    use AuthorizesRequests;

    public Brand $brand;

    #[Url(except: '')]
    public string $chat = '';

    #[Url(except: '')]
    public string $search = '';

    /** all, unread, or open (chats that can still be answered). */
    #[Url(except: 'all')]
    public string $filter = 'all';

    public function mount(Brand $brand): void
    {
        $this->brand = $brand;
        $this->authorize('viewAny', Conversation::class);
    }

    public function open(int $conversationId): void
    {
        $this->chat = (string) $conversationId;
    }

    /** Back to the list on a phone. */
    #[On('inbox-back')]
    public function closeChat(): void
    {
        $this->chat = '';
    }

    #[On('conversation-updated')]
    public function refreshList(): void
    {
        // Re-render so the list shows the reply that was just sent.
    }

    public function render(): View
    {
        $user = auth()->user();
        $like = '%'.$this->search.'%';
        $replyableSince = now()->subHours(Conversation::REPLY_WINDOW_HOURS);
        $visible = fn (): Builder => Conversation::query()->visibleTo($user);

        $conversations = $visible()
            ->with(['lead.status', 'lead.assignee', 'lead.department', 'source'])
            ->when($this->search !== '', fn (Builder $query): Builder => $query->where(fn (Builder $inner): Builder => $inner
                ->where('contact_name', 'like', $like)
                ->orWhere('last_message_preview', 'like', $like)))
            ->when($this->filter === 'unread', fn (Builder $query): Builder => $query->where('unread_count', '>', 0))
            ->when($this->filter === 'open', fn (Builder $query): Builder => $query->where('last_inbound_at', '>', $replyableSince))
            ->orderByDesc('last_message_at')
            ->limit(100)
            ->get();

        $selected = $this->chat !== '' ? $conversations->firstWhere('id', (int) $this->chat) : null;

        // A chat opened from a notification, or hidden by the filter, may not be in the list.
        if ($selected === null && $this->chat !== '') {
            $selected = $visible()->with(['lead.status', 'lead.assignee', 'lead.department', 'source'])->find((int) $this->chat);
        }

        return view('meta::livewire.app.inbox', [
            'conversations' => $conversations,
            'selected' => $selected,
            'total' => $visible()->count(),
            'unread' => $visible()->where('unread_count', '>', 0)->count(),
            'fullContact' => $selected?->lead !== null && app(ContactVisibility::class)->canSeeFull($user, $selected->lead),
        ]);
    }
}
