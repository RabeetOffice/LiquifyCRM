<?php

declare(strict_types=1);

namespace App\Modules\Meta\Livewire\App;

use App\Modules\Brands\Models\Brand;
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
 * Managers their departments, Agents their own), newest first.
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

    public function mount(Brand $brand): void
    {
        $this->brand = $brand;
        $this->authorize('viewAny', Conversation::class);
    }

    public function open(int $conversationId): void
    {
        $this->chat = (string) $conversationId;
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

        $conversations = Conversation::query()
            ->visibleTo($user)
            ->with(['lead.status', 'lead.assignee', 'source'])
            ->when($this->search !== '', fn (Builder $query): Builder => $query->where(fn (Builder $inner): Builder => $inner
                ->where('contact_name', 'like', $like)
                ->orWhere('last_message_preview', 'like', $like)))
            ->orderByDesc('last_message_at')
            ->limit(100)
            ->get();

        $selected = $this->chat !== '' ? $conversations->firstWhere('id', (int) $this->chat) : null;

        // A chat opened from a notification may sit outside the first 100.
        if ($selected === null && $this->chat !== '') {
            $selected = Conversation::query()->visibleTo($user)->with(['lead.status', 'lead.assignee', 'source'])->find((int) $this->chat);
        }

        return view('meta::livewire.app.inbox', [
            'conversations' => $conversations,
            'selected' => $selected,
        ]);
    }
}
