<?php

declare(strict_types=1);

namespace App\Modules\Meta\Livewire\App;

use App\Modules\Auth\Models\User;
use App\Modules\Brands\Models\Brand;
use App\Modules\Chat\Actions\CaptureVisitorDetails;
use App\Modules\Chat\Actions\ChangeChat;
use App\Modules\Chat\Actions\ReplyToVisitor;
use App\Modules\Chat\Models\ChatPageView;
use App\Modules\Chat\Models\ChatSession;
use App\Modules\Chat\Support\ChatAccess;
use App\Modules\Chat\Support\LiveChat;
use App\Modules\Leads\Actions\AssignLead;
use App\Modules\Leads\Livewire\Concerns\EditsLeadDetails;
use App\Modules\Leads\Livewire\Concerns\TakesLeads;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Support\AssignableAgents;
use App\Modules\Leads\Support\ContactVisibility;
use App\Modules\Meta\Actions\SyncContactProfile;
use App\Modules\Meta\Models\Conversation;
use App\Modules\Meta\Support\Instagram;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use RuntimeException;

/**
 * Inbox: Messenger and Instagram threads of the leads a person may see
 * (Admins all, Managers their departments, Agents their own), newest first,
 * in three panes: the chats, the open thread, and the lead it belongs to.
 * From the third pane the lead can be handed to an Agent and its details
 * filled in without leaving the chat.
 */
#[Layout('components.layouts.app')]
#[Title('Inbox')]
class Inbox extends Component
{
    use AuthorizesRequests;
    use EditsLeadDetails;
    use TakesLeads;

    public Brand $brand;

    #[Url(except: '')]
    public string $chat = '';

    /** The "Add their details" form beside a website visitor's chat. */
    public bool $showVisitorDetails = false;

    public string $visitorName = '';

    public string $visitorEmail = '';

    public string $visitorPhone = '';

    #[Url(except: '')]
    public string $search = '';

    /** all, unread, open (chats that can still be answered), or visitors (website chats with no lead yet). */
    #[Url(except: 'all')]
    public string $filter = 'all';

    /** "" for every channel, or messenger / instagram / livechat. */
    #[Url(except: '')]
    public string $channel = '';

    public bool $showAssign = false;

    public string $assignTo = '';

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

    /** The two buttons in the head of the thread, for screens too narrow to show the lead beside the chat. */
    #[On('inbox-assign')]
    public function openAssign(): void
    {
        $lead = $this->detailsLead();

        if ($lead === null) {
            return;
        }

        if (! $this->user()->can('assign', $lead)) {
            $this->toastWarning('You cannot assign this lead', 'Only an Admin, or the Manager of the lead\x27s department, can give it to someone.');

            return;
        }

        $this->assignTo = (string) ($lead->assigned_to ?? '');
        $this->showAssign = true;
    }

    /** "Take this chat" under a free thread: the lead and its chat become the person's. */
    #[On('inbox-take')]
    public function takeChat(): void
    {
        $lead = $this->detailsLead();

        if ($lead !== null) {
            $this->takeLead((int) $lead->getKey());
            $this->dispatch('conversation-updated');
        }
    }

    public function assignFromDialog(): void
    {
        $this->assignLead($this->assignTo);
        $this->showAssign = false;
    }

    #[On('inbox-details')]
    public function editDetails(): void
    {
        $lead = $this->detailsLead();

        if ($lead !== null && ! $this->user()->can('update', $lead)) {
            $this->toastWarning('You cannot change this lead');

            return;
        }

        $this->openDetails();
    }

    /** Ask Facebook (or Instagram) again for this person's name and picture, and say plainly what came back. */
    /** The website visitor's chat open now, when it has no lead yet and this person may answer it. */
    private function visitorChat(): Conversation
    {
        $conversation = $this->chat === '' ? null : Conversation::query()->visibleTo($this->user())->with(['visitor', 'widget'])->find((int) $this->chat);

        abort_if($conversation === null || ! $conversation->isLiveChat(), 404);
        $this->authorize('reply', $conversation);

        return $conversation;
    }

    /** Somebody of the team writes down what the visitor told them: with an email or a phone number it makes the lead. */
    public function openVisitorDetails(): void
    {
        $visitor = $this->visitorChat()->visitor;

        $this->resetErrorBag();
        $this->visitorName = (string) ($visitor->name ?? '');
        $this->visitorEmail = '';
        $this->visitorPhone = '';
        $this->showVisitorDetails = true;
    }

    public function saveVisitorDetails(CaptureVisitorDetails $capture): void
    {
        $conversation = $this->visitorChat();
        $this->resetErrorBag();

        if ($conversation->lead !== null || $conversation->visitor === null || $conversation->widget === null) {
            $this->showVisitorDetails = false;

            return;
        }

        if (trim($this->visitorEmail) === '' && trim($this->visitorPhone) === '') {
            $this->addError('visitorEmail', 'Add an email address or a phone number: that is what makes the lead.');

            return;
        }

        $result = $capture->handle($conversation->widget, $conversation->visitor, ['name' => $this->visitorName, 'email' => $this->visitorEmail, 'phone' => $this->visitorPhone]);

        foreach ($result['errors'] as $field => $message) {
            $this->addError(match ($field) {
                'phone' => 'visitorPhone', 'name' => 'visitorName', default => 'visitorEmail'
            }, str_replace(['your email', 'your phone'], ['the email', 'the phone'], $message));
        }

        if ($result['errors'] !== []) {
            return;
        }

        $this->showVisitorDetails = false;
        $this->reset('visitorName', 'visitorEmail', 'visitorPhone');
        $this->toastSuccess($result['lead'] !== null ? 'Lead made' : 'Details saved', $result['lead'] !== null ? 'The chat is now on the lead '.$result['lead']->name.'.' : null);
    }

    /** Ask the visitor in the chat: a message, with the chat box's details form under it. */
    public function askVisitorDetails(ReplyToVisitor $replies): void
    {
        $conversation = $this->visitorChat();

        if ($conversation->lead !== null) {
            return;
        }

        try {
            $replies->askForDetails($conversation, $this->user());
        } catch (RuntimeException $exception) {
            $this->toastWarning($exception->getMessage());

            return;
        }

        $this->toastSuccess('Asked in the chat', 'The visitor sees a form for their name and email or phone number.');
    }

    /** Give a visitor's chat (one with no lead yet) to somebody of the team, or back to everybody with "". */
    public function assignChat(string $userId, ChangeChat $changes): void
    {
        $conversation = $this->chat === '' ? null : Conversation::query()->visibleTo($this->user())->find((int) $this->chat);

        if ($conversation === null) {
            return;
        }

        $this->authorize('assignChat', $conversation);

        $to = $userId === '' ? null : (ChatAccess::team((int) $this->brand->getKey())->firstWhere('id', (int) $userId) ?? abort(403));

        try {
            $changes->assign($conversation, $to, $this->user());
        } catch (RuntimeException $exception) {
            $this->toastWarning($exception->getMessage());

            return;
        }

        $this->toastSuccess($to !== null ? 'Given to '.$to->name : 'Back with the team');
    }

    public function refreshProfile(SyncContactProfile $sync): void
    {
        $conversation = $this->chat === '' ? null : Conversation::query()->visibleTo($this->user())->with(['lead', 'source'])->find((int) $this->chat);

        if ($conversation === null || $conversation->isLiveChat()) {
            return;
        }

        $result = $sync->handle($conversation, force: true);
        $service = $conversation->isInstagram() ? 'Instagram' : 'Facebook';

        if ($result['name'] || $result['picture']) {
            $this->toastSuccess('Updated from '.$service, $result['name'] ? 'The name'.($result['picture'] ? ' and the picture are' : ' is').' in.' : 'The picture is in.');

            return;
        }

        $this->toastWarning(
            $service.' did not share this person\'s profile',
            ($result['reason'] !== null ? $service.' says: '.$result['reason'].' ' : '').'Meta shares names and pictures once your app has passed its review, and before that only for people with a role on the app. You can type the name in with Edit details.',
        );
    }

    /** Hand the lead of the open chat to an Agent (or back to the queue with ""), without leaving the chat. */
    public function assignLead(string $agentId): void
    {
        $lead = $this->detailsLead();

        if ($lead === null) {
            return;
        }

        $this->authorize('assign', $lead);

        $user = $this->user();
        $agent = null;

        if ($agentId !== '') {
            // Somebody of the lead's department, as in the assign menu everywhere else.
            $agent = app(AssignableAgents::class)->find($user, $this->brand, (int) $agentId, $lead->department_id) ?? abort(403);
        }

        if ($lead->assigned_to === $agent?->getKey()) {
            return;
        }

        app(AssignLead::class)->handle($lead, $agent, $user);

        $this->toastSuccess($agent !== null ? 'Assigned to '.$agent->name : 'Returned to the queue');
    }

    /** The lead behind the open chat, when this person may see it. */
    protected function detailsLead(): ?Lead
    {
        if ($this->chat === '') {
            return null;
        }

        return Conversation::query()->visibleTo($this->user())->with('lead')->find((int) $this->chat)?->lead;
    }

    public function render(): View
    {
        $user = $this->user();
        $like = '%'.$this->search.'%';
        $replyableSince = now()->subHours(Conversation::REPLY_WINDOW_HOURS);
        $visible = fn (): Builder => Conversation::query()->visibleTo($user);
        $handles = Instagram::ready();

        // The website's chats have a screen of their own in the Live chat tab (user request, 4 Nov 2026): the Inbox is
        // Messenger and Instagram, and Live chats is every chat of the website, visitors with no lead included.
        $inChats = $this->place() === 'livechat';
        $liveChat = LiveChat::ready();
        $seesVisitors = $liveChat && ChatAccess::canSeeVisitors($user, (int) $this->brand->getKey());
        // Closing, assigning and answering need the columns of the second update.
        $work = $liveChat && LiveChat::workReady();

        // The chat of an archived lead is out of the list too; a new message brings the lead back (Lead::heardFrom).
        $listed = fn (): Builder => Conversation::query()->listedFor($user)
            ->when($inChats, fn (Builder $query): Builder => $query->where('channel', Conversation::CHANNEL_LIVECHAT), fn (Builder $query): Builder => $query->where('channel', '!=', Conversation::CHANNEL_LIVECHAT));
        $relations = ['lead.status', 'lead.assignee', 'lead.department', 'source', ...($liveChat ? ['visitor', 'widget'] : []), ...($work ? ['chatAssignee'] : [])];

        $conversations = $listed()
            ->with($relations)
            // Live chats: Visitors is the chats with no lead yet, Closed the closed ones, every other view the open ones.
            ->when($inChats && $this->filter === 'visitors', fn (Builder $query): Builder => $query->anonymous())
            ->when($inChats && $work, fn (Builder $query): Builder => $this->filter === 'closed' ? $query->where('chat_status', 'closed') : $query->where('chat_status', '!=', 'closed'))
            ->when($this->search !== '', fn (Builder $query): Builder => $query->where(fn (Builder $inner): Builder => $inner
                ->where('contact_name', 'like', $like)
                ->when($handles, fn (Builder $handle): Builder => $handle->orWhere('contact_handle', 'like', '%'.ltrim($this->search, '@').'%'))
                ->orWhere('last_message_preview', 'like', $like)))
            ->when(! $inChats && in_array($this->channel, [Conversation::CHANNEL_MESSENGER, Conversation::CHANNEL_INSTAGRAM], true), fn (Builder $query): Builder => $query->where('channel', $this->channel))
            ->when($this->filter === 'unread', fn (Builder $query): Builder => $query->where('unread_count', '>', 0))
            ->when(! $inChats && $this->filter === 'open', fn (Builder $query): Builder => $query->where('last_inbound_at', '>', $replyableSince))
            ->orderByDesc('last_message_at')
            ->limit(100)
            ->get();

        $selected = $this->chat !== '' ? $conversations->firstWhere('id', (int) $this->chat) : null;

        // A chat opened from a notification, or hidden by the filter, may not be in the list.
        if ($selected === null && $this->chat !== '') {
            $selected = $visible()->with($relations)->find((int) $this->chat);
        }

        // A chat of the other screen (an older bell entry, a bookmark) opens where it belongs.
        if ($selected !== null && $selected->isLiveChat() !== $inChats) {
            $this->redirect(route($selected->isLiveChat() ? 'brand.live-chat.chats' : 'brand.inbox', ['brand' => $this->brand->slug, 'chat' => $selected->getKey()]), navigate: true);
        }

        $lead = $selected?->lead;
        $canAssign = $lead !== null && $user->can('assign', $lead);

        // Live chat › Chats: who is on the website now, to open their chat or start one (user request, 12 Nov 2026).
        $liveNow = $inChats && $seesVisitors && LiveChat::trackingReady()
            ? ChatSession::query()->online()->with('visitor')->orderByDesc('page_changed_at')->limit(12)->get()
            : new Collection;

        return view('meta::livewire.app.inbox', [
            'liveNow' => $liveNow,
            'liveChats' => ChatSession::chatsOf($liveNow, $user),
            'liveVisitorsUrl' => $this->brand->url('live-chat/visitors'),
            'conversations' => $conversations,
            'selected' => $selected,
            'inChats' => $inChats,
            'total' => $listed()->count(),
            'unread' => $listed()->where('unread_count', '>', 0)->when($inChats && $work, fn (Builder $query): Builder => $query->where('chat_status', '!=', 'closed'))->count(),
            // The channel choice shows once there is more than Messenger to choose from.
            'hasInstagram' => ! $inChats && ($this->channel !== '' || $visible()->where('channel', Conversation::CHANNEL_INSTAGRAM)->exists()),
            'seesVisitors' => $seesVisitors,
            'visitors' => $inChats && $seesVisitors ? $listed()->anonymous()->when($work, fn (Builder $query): Builder => $query->where('chat_status', '!=', 'closed'))->count() : 0,
            'canAssignChat' => $work && $selected !== null && $user->can('assignChat', $selected),
            'chatTeam' => $work && $selected !== null && $selected->isLiveChat() && $selected->lead_id === null && $user->can('assignChat', $selected) ? ChatAccess::team((int) $this->brand->getKey())->mapWithKeys(fn (User $person): array => [$person->getKey() => $person->name.($person->is($user) ? ' (you)' : '')])->all() : [],
            'workReady' => $work,
            // The pages the visitor read on the website before and while they chat.
            'trail' => $work && LiveChat::trackingReady() && $selected !== null && $selected->isLiveChat() && $selected->chat_visitor_id !== null ? $this->trailOf((int) $selected->chat_visitor_id) : new Collection,
            'fullContact' => $lead !== null && app(ContactVisibility::class)->canSeeFull($user, $lead),
            'canAssign' => $canAssign,
            'canUpdate' => $lead !== null && $user->can('update', $lead),
            'agentOptions' => $canAssign ? app(AssignableAgents::class)->for($user, $this->brand) : [],
        ]);
    }

    /** Which screen this is: the Inbox (Messenger and Instagram) or Live chats (the website's chats, LiveChats). */
    protected function place(): string
    {
        return 'inbox';
    }

    /**
     * The pages a chat visitor's browser read, newest first.
     *
     * @return Collection<int, ChatPageView>
     */
    private function trailOf(int $visitorId): Collection
    {
        $session = ChatSession::query()->where('chat_visitor_id', $visitorId)->latest('last_seen_at')->first();

        return $session !== null ? ChatPageView::query()->where('chat_session_id', $session->getKey())->latest('id')->limit(12)->get() : new Collection;
    }

    private function user(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
