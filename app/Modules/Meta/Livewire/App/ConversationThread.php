<?php

declare(strict_types=1);

namespace App\Modules\Meta\Livewire\App;

use App\Modules\Core\Support\CurrentBrand;
use App\Modules\Meta\Actions\ReplyOnMessenger;
use App\Modules\Meta\Exceptions\MetaException;
use App\Modules\Meta\Models\Conversation;
use App\Modules\Meta\Models\Message;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * One Messenger or Instagram thread: the messages, how long the 24-hour
 * reply window stays open, and the reply box. Used in the Inbox and on the
 * lead page.
 */
final class ConversationThread extends Component
{
    use AuthorizesRequests;

    #[Locked]
    public int $conversationId;

    /** True in the Inbox, where the head also links to the lead and, on a phone, back to the list. */
    #[Locked]
    public bool $inbox = false;

    public string $reply = '';

    public function mount(int $conversationId, bool $inbox = false): void
    {
        $this->conversationId = $conversationId;
        $this->inbox = $inbox;

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

    public function render(): View
    {
        $conversation = $this->conversation();

        return view('meta::livewire.app.conversation-thread', [
            'conversation' => $conversation,
            // The newest 100, shown oldest first.
            'messages' => Message::query()->where('conversation_id', $conversation->getKey())->with('sender')->latest('id')->limit(100)->get()->reverse()->values(),
            'canReply' => (bool) auth()->user()?->can('reply', $conversation),
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
        return Conversation::query()->with(['lead.status', 'source'])->findOrFail($this->conversationId);
    }
}
