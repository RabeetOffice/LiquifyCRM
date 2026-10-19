<?php

declare(strict_types=1);

namespace App\Modules\Meta\Livewire\App;

use App\Modules\Auth\Models\User;
use App\Modules\Brands\Models\Brand;
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
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Inbox: Messenger and Instagram threads of the leads a person may see
 * (Admins all, Managers their departments, Agents their own), newest first,
 * in three panes: the chats, the open thread, and the lead it belongs to.
 * From the third pane the lead can be handed to an Agent and its details
 * filled in without leaving the chat.
 */
#[Layout('components.layouts.app')]
#[Title('Inbox')]
final class Inbox extends Component
{
    use AuthorizesRequests;
    use EditsLeadDetails;
    use TakesLeads;

    public Brand $brand;

    #[Url(except: '')]
    public string $chat = '';

    #[Url(except: '')]
    public string $search = '';

    /** all, unread, or open (chats that can still be answered). */
    #[Url(except: 'all')]
    public string $filter = 'all';

    /** "" for every channel, or messenger / instagram. */
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
    public function refreshProfile(SyncContactProfile $sync): void
    {
        $conversation = $this->chat === '' ? null : Conversation::query()->visibleTo($this->user())->with(['lead', 'source'])->find((int) $this->chat);

        if ($conversation === null) {
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

        $channels = [Conversation::CHANNEL_MESSENGER, Conversation::CHANNEL_INSTAGRAM];
        $handles = Instagram::ready();

        // The chat of an archived lead is out of the list too; a new message brings the lead back (Lead::heardFrom).
        $listed = fn (): Builder => $visible()->whereHas('lead', fn (Builder $lead): Builder => $lead->unarchived());

        $conversations = $listed()
            ->with(['lead.status', 'lead.assignee', 'lead.department', 'source'])
            ->when($this->search !== '', fn (Builder $query): Builder => $query->where(fn (Builder $inner): Builder => $inner
                ->where('contact_name', 'like', $like)
                ->when($handles, fn (Builder $handle): Builder => $handle->orWhere('contact_handle', 'like', '%'.ltrim($this->search, '@').'%'))
                ->orWhere('last_message_preview', 'like', $like)))
            ->when(in_array($this->channel, $channels, true), fn (Builder $query): Builder => $query->where('channel', $this->channel))
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

        $lead = $selected?->lead;
        $canAssign = $lead !== null && $user->can('assign', $lead);

        return view('meta::livewire.app.inbox', [
            'conversations' => $conversations,
            'selected' => $selected,
            'total' => $listed()->count(),
            'unread' => $listed()->where('unread_count', '>', 0)->count(),
            // The channel choice shows once there is more than Messenger to choose from.
            'hasInstagram' => $this->channel !== '' || $visible()->where('channel', Conversation::CHANNEL_INSTAGRAM)->exists(),
            'fullContact' => $lead !== null && app(ContactVisibility::class)->canSeeFull($user, $lead),
            'canAssign' => $canAssign,
            'canUpdate' => $lead !== null && $user->can('update', $lead),
            'agentOptions' => $canAssign ? app(AssignableAgents::class)->for($user, $this->brand) : [],
        ]);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
