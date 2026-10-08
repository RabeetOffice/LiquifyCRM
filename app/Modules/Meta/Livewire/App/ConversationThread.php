<?php

declare(strict_types=1);

namespace App\Modules\Meta\Livewire\App;

use App\Modules\Meta\Actions\ReplyOnMessenger;
use App\Modules\Meta\Exceptions\MetaException;
use App\Modules\Meta\Models\Conversation;
use App\Modules\Meta\Models\Message;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * One Messenger thread: the messages, how long the 24-hour reply window
 * stays open, and the reply box. Used in the Inbox and on the lead page.
 */
final class ConversationThread extends Component
{
    use AuthorizesRequests;

    #[Locked]
    public int $conversationId;

    public string $reply = '';

    public function mount(int $conversationId): void
    {
        $this->conversationId = $conversationId;

        $conversation = $this->conversation();
        $this->authorize('view', $conversation);

        if ($conversation->unread_count > 0) {
            $conversation->forceFill(['unread_count' => 0])->save();
        }
    }

    public function send(ReplyOnMessenger $action): void
    {
        $conversation = $this->conversation();
        $this->authorize('reply', $conversation);

        $this->validate(['reply' => ['required', 'string', 'max:2000']], attributes: ['reply' => 'message']);

        try {
            $action->handle($conversation, $this->reply, auth()->user());
        } catch (MetaException $exception) {
            $this->addError('reply', $exception->getMessage());

            return;
        }

        $this->reply = '';
        $this->dispatch('conversation-updated');
    }

    public function render(): View
    {
        $conversation = $this->conversation();

        return view('meta::livewire.app.conversation-thread', [
            'conversation' => $conversation,
            // The newest 100, shown oldest first.
            'messages' => Message::query()->where('conversation_id', $conversation->getKey())->with('sender')->latest('id')->limit(100)->get()->reverse()->values(),
            'canReply' => (bool) auth()->user()?->can('reply', $conversation),
        ]);
    }

    private function conversation(): Conversation
    {
        return Conversation::query()->with(['lead', 'source'])->findOrFail($this->conversationId);
    }
}
