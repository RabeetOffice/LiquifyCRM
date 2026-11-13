<?php

declare(strict_types=1);

namespace App\Modules\Meta\Livewire\App;

use App\Modules\Auth\Models\User;
use App\Modules\Chat\Actions\ChangeChat;
use App\Modules\Chat\Actions\ReplyToVisitor;
use App\Modules\Chat\Actions\StartCall;
use App\Modules\Chat\Models\CannedReply;
use App\Modules\Chat\Models\ChatWidget;
use App\Modules\Chat\Support\ChatFiles;
use App\Modules\Chat\Support\ChatTyping;
use App\Modules\Chat\Support\LiveChat;
use App\Modules\Core\Support\CurrentBrand;
use App\Modules\Meta\Actions\ReplyOnMessenger;
use App\Modules\Meta\Exceptions\MetaException;
use App\Modules\Meta\Models\Conversation;
use App\Modules\Meta\Models\Message;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\UploadedFile;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithFileUploads;
use RuntimeException;

/**
 * One Messenger or Instagram thread: the messages, how long the 24-hour
 * reply window stays open, and the reply box. Used in the Inbox and on the
 * lead page.
 */
final class ConversationThread extends Component
{
    use AuthorizesRequests;
    use WithFileUploads;

    #[Locked]
    public int $conversationId;

    /** True in the Inbox, where the head also links to the lead and, on a phone, back to the list. */
    #[Locked]
    public bool $inbox = false;

    public string $reply = '';

    /** In a website chat: reply to the visitor, or note for the team only. */
    public string $mode = 'reply';

    /** A file chosen to go with the reply (website chat only). */
    public mixed $upload = null;

    public function mount(int $conversationId, bool $inbox = false): void
    {
        $this->conversationId = $conversationId;
        $this->inbox = $inbox;

        $conversation = $this->conversation();
        $this->authorize('view', $conversation);

        $this->markRead($conversation);
    }

    /** Taken (or given) a moment ago: the reply box shows at once, and the chat counts as read by its new owner. */
    #[On('lead-updated')]
    public function leadChanged(): void
    {
        $this->markRead($this->conversation());
    }

    /** Read only by whoever works the chat: a colleague looking in leaves it unread for its owner. */
    private function markRead(Conversation $conversation): void
    {
        if ($conversation->unread_count > 0 && (bool) auth()->user()?->can('reply', $conversation)) {
            $conversation->forceFill(['unread_count' => 0])->save();
        }
    }

    public function send(ReplyOnMessenger $action): void
    {
        $conversation = $this->conversation();

        if ($conversation->isLiveChat()) {
            $this->sendInLiveChat($conversation);

            return;
        }

        $this->authorize('reply', $conversation);

        $this->validate(['reply' => ['required', 'string']], attributes: ['reply' => 'message']);

        if (($tooLong = $conversation->replyTooLong(trim($this->reply))) !== null) {
            $this->addError('reply', $tooLong);

            return;
        }

        try {
            $action->handle($conversation, $this->reply, auth()->user());
        } catch (MetaException $exception) {
            $this->addError('reply', $exception->getMessage());

            return;
        }

        $this->reply = '';
        $this->dispatch('conversation-updated');
    }

    /** An answer to the visitor, or a note the visitor never sees. */
    private function sendInLiveChat(Conversation $conversation): void
    {
        $this->authorize('reply', $conversation);
        // A file may go without words; a note never carries one.
        $file = $this->mode === 'note' || ! LiveChat::extrasReady() || ! $this->upload instanceof UploadedFile ? null : $this->upload;

        $this->validate(['reply' => [$file !== null ? 'nullable' : 'required', 'string']], attributes: ['reply' => 'message']);

        $lines = app(ReplyToVisitor::class);

        try {
            $this->mode === 'note' ? $lines->note($conversation, $this->user(), $this->reply) : $lines->reply($conversation, $this->user(), (string) $this->reply, $file);
        } catch (RuntimeException $exception) {
            $this->addError('reply', $exception->getMessage());

            return;
        }

        $this->reply = '';
        $this->upload = null;
        $this->dispatch('conversation-updated');
    }

    public function close(ChangeChat $changes): void
    {
        $conversation = $this->conversation();
        $this->authorize('work', $conversation);

        $changes->close($conversation, $this->user());
        $this->dispatch('conversation-updated');
    }

    /**
     * Invite the visitor to a voice call (user request, 13 Nov 2026): a message with a button in the chat box. Only the
     * visitor starts a call; this asks them to.
     */
    public function inviteToCall(ReplyToVisitor $replies): void
    {
        $conversation = $this->conversation();
        $this->authorize('reply', $conversation);
        abort_unless($this->callsOffered($conversation), 404);

        try {
            $replies->inviteToCall($conversation, $this->user());
        } catch (RuntimeException $exception) {
            $this->addError('reply', $exception->getMessage());

            return;
        }

        $this->dispatch('conversation-updated');
    }

    /** Whether this chat's box on the website has the phone button. */
    private function callsOffered(Conversation $conversation): bool
    {
        if (! $conversation->isLiveChat() || ! LiveChat::callsReady() || $conversation->chat_widget_id === null) {
            return false;
        }

        $widget = ChatWidget::query()->withoutGlobalScopes()->find($conversation->chat_widget_id);

        return $widget !== null && StartCall::offered($widget);
    }

    public function reopen(ChangeChat $changes): void
    {
        $conversation = $this->conversation();
        $this->authorize('work', $conversation);

        $changes->reopen($conversation, $this->user());
        $this->dispatch('conversation-updated');
    }

    /** Who has the chat: its lead's owner, or for a visitor with no lead the person who took it. */
    private function owner(Conversation $conversation): ?string
    {
        if ($conversation->lead !== null) {
            return $conversation->lead->assignee?->name;
        }

        return $conversation->isLiveChat() && LiveChat::workReady() && $conversation->chatAssignee !== null ? $conversation->chatAssignee->name : null;
    }

    /**
     * What this person may put in the box with a slash: the company's, the brand's and their own saved replies, with
     * this chat's names filled in.
     *
     * @return list<array{id: int, shortcut: string, title: string, body: string}>
     */
    private function savedReplies(Conversation $conversation): array
    {
        if (! LiveChat::extrasReady()) {
            return [];
        }

        $brand = app(CurrentBrand::class)->get();

        return CannedReply::query()->usableBy($this->user(), (int) $conversation->brand_id)->orderBy('shortcut')->get()
            ->map(fn (CannedReply $reply): array => ['id' => (int) $reply->getKey(), 'shortcut' => $reply->shortcut, 'title' => $reply->title, 'body' => $reply->filled($conversation->displayName(), $this->user()->name, $brand->name ?? '')])
            ->values()
            ->all();
    }

    private function user(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }

    public function render(): View
    {
        $conversation = $this->conversation();
        // Open on the screen when new messages come in (the live reload, the poll): they are read (20 Oct 2026).
        $this->markRead($conversation);

        return view('meta::livewire.app.conversation-thread', [
            'conversation' => $conversation,
            // The newest 100, shown oldest first.
            'messages' => Message::query()->where('conversation_id', $conversation->getKey())->with(['sender', ...(LiveChat::extrasReady() ? ['chatFiles'] : [])])->latest('id')->limit(100)->get()->reverse()->values(),
            'canReply' => (bool) auth()->user()?->can('reply', $conversation),
            // Front sales: a free chat is taken before it is answered; a colleague's is only read.
            'canTake' => $conversation->lead !== null && (bool) auth()->user()?->can('take', $conversation->lead),
            'owner' => $this->owner($conversation),
            'filesReady' => LiveChat::extrasReady(),
            'accept' => ChatFiles::accept(),
            'brandSlug' => app(CurrentBrand::class)->get()?->slug,
            'savedReplies' => $this->savedReplies($conversation),
            'typingUrl' => $conversation->isLiveChat() && ($brand = app(CurrentBrand::class)->get()) !== null ? route('brand.conversations.typing', ['brand' => $brand->slug, 'conversation' => $conversation->getKey()]) : null,
            'isLiveChat' => $conversation->isLiveChat(),
            'closed' => $conversation->isClosed(),
            'canWork' => $conversation->isLiveChat() && (bool) auth()->user()?->can('work', $conversation),
            'canInviteCall' => $this->callsOffered($conversation) && (bool) auth()->user()?->can('reply', $conversation),
            'visitorTyping' => $conversation->isLiveChat() && ChatTyping::visitorIsTyping((int) $conversation->getKey()),
            // The Inbox head's Assign button, for screens too narrow for the side pane: only for who may assign the lead.
            'canAssign' => $conversation->lead !== null && (bool) auth()->user()?->can('assign', $conversation->lead),
            'leadUrl' => $this->leadUrl($conversation),
        ]);
    }

    /** The Inbox head links to the lead; on the lead page that would point at itself. */
    private function leadUrl(Conversation $conversation): ?string
    {
        $brand = app(CurrentBrand::class)->get();

        if (! $this->inbox || $conversation->lead_id === null || $brand === null) {
            return null;
        }

        return route('brand.leads.show', ['brand' => $brand->slug, 'lead' => $conversation->lead_id]);
    }

    private function conversation(): Conversation
    {
        return Conversation::query()->with(['lead.status', 'source', ...(LiveChat::ready() ? ['visitor', 'widget'] : []), ...(LiveChat::workReady() ? ['chatAssignee'] : [])])->findOrFail($this->conversationId);
    }
}
