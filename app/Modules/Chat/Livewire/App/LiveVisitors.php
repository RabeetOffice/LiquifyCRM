<?php

declare(strict_types=1);

namespace App\Modules\Chat\Livewire\App;

use App\Modules\Auth\Models\User;
use App\Modules\Brands\Models\Brand;
use App\Modules\Chat\Actions\InviteVisitor;
use App\Modules\Chat\Models\ChatPageView;
use App\Modules\Chat\Models\ChatSession;
use App\Modules\Chat\Support\ChatAccess;
use App\Modules\Chat\Support\LiveChat;
use App\Modules\Core\Livewire\Concerns\WithToasts;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use RuntimeException;

/**
 * Who is on the brand's websites right now (user request, 3 Nov 2026, "the way Tawk.to does it"): each browser the chat
 * button reported in the last minute and a half, the page it is on, how long it has been there, where it came from, and
 * whether it is chatting. The team opens one to see the pages it read, and invites it to chat. Seen by Admins, the brand's
 * Managers and its Agents (unless the brand's chat says only Managers).
 */
#[Layout('components.layouts.app')]
#[Title('Live visitors')]
final class LiveVisitors extends Component
{
    use WithToasts;

    public Brand $brand;

    /** The browser whose page trail is open. */
    #[Locked]
    public ?int $open = null;

    public bool $showInvite = false;

    #[Locked]
    public ?int $inviting = null;

    public string $invitation = '';

    /** A browser to start a chat with, from Live chat › Chats ("On the website now"). */
    #[Url(as: 'start', except: '')]
    public string $start = '';

    public function mount(Brand $brand): void
    {
        $this->brand = $brand;
        abort_unless(LiveChat::trackingReady() && ChatAccess::canSeeVisitors($this->user(), (int) $brand->getKey()), 403);

        if (ctype_digit($this->start) && $this->online()->whereKey((int) $this->start)->exists()) {
            $this->invite((int) $this->start);
        }

        $this->start = '';
    }

    public function toggle(int $sessionId): void
    {
        $this->open = $this->open === $sessionId ? null : $sessionId;
    }

    public function invite(int $sessionId): void
    {
        $session = $this->online()->findOrFail($sessionId);

        $this->inviting = $sessionId;
        $this->invitation = 'Hi! Can I help you with anything'.($session->current_title !== null ? ' on "'.mb_substr($session->current_title, 0, 60).'"' : '').'?';
        $this->resetErrorBag();
        $this->showInvite = true;
    }

    public function sendInvitation(InviteVisitor $invite): void
    {
        $session = $this->inviting !== null ? ChatSession::query()->with(['widget', 'visitor'])->findOrFail($this->inviting) : null;

        if ($session === null) {
            return;
        }

        try {
            $chat = $invite->handle($session, $this->user(), $this->invitation);
        } catch (RuntimeException $exception) {
            $this->addError('invitation', $exception->getMessage());

            return;
        }

        $this->showInvite = false;
        $this->reset('inviting');

        // Straight into the chat: their answer shows there.
        $this->redirect($this->brand->url('live-chat/chats').'?chat='.$chat->getKey(), navigate: true);
    }

    public function render(): View
    {
        $sessions = $this->online()->with(['visitor.lead', 'widget'])->orderByDesc('page_changed_at')->limit(200)->get();

        return view('chat::livewire.app.live-visitors', [
            'sessions' => $sessions,
            'chats' => ChatSession::chatsOf($sessions, $this->user()),
            'chatsUrl' => $this->brand->url('live-chat/chats'),
            'trail' => $this->open !== null ? $this->trailOf($this->open) : new Collection,
            'inboxUrl' => $this->brand->url('inbox'),
        ]);
    }

    /** @return Collection<int, ChatPageView> the pages a browser read, newest first */
    private function trailOf(int $sessionId): Collection
    {
        return ChatPageView::query()->where('chat_session_id', $sessionId)->latest('id')->limit(15)->get();
    }

    /** @return Builder<ChatSession> */
    private function online(): Builder
    {
        return ChatSession::query()->online();
    }

    private function user(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
