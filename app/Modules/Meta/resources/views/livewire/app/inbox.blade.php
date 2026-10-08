<div class="flex flex-col gap-6">
    <x-ui.page-header title="Inbox" subtitle="Messenger chats of your leads. Reply here or from the lead page." />

    @if ($conversations->isEmpty() && $search === '')
        <x-ui.card>
            <x-ui.empty title="No chats yet" description="When someone writes to a Facebook Page of this brand, the chat appears here and a lead is created in the New queue." icon="heroicon-o-chat-bubble-left-right" />
        </x-ui.card>
    @else
        <div class="lq-inbox" wire:poll.15s>
            <x-ui.card flush class="lq-inbox-list">
                <div class="lq-toolbar">
                    <div class="relative w-full">
                        <x-heroicon-o-magnifying-glass class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 lq-muted" />
                        <input type="search" wire:model.live.debounce.300ms="search" class="lq-input lq-input-sm" style="padding-inline-start: 2.25rem" placeholder="Search chats…">
                    </div>
                </div>
                <ul class="lq-inbox-items">
                    @forelse ($conversations as $conversation)
                        <li wire:key="chat-{{ $conversation->getKey() }}">
                            <button type="button" @class(['lq-inbox-item', 'lq-inbox-item-active' => $selected?->is($conversation)]) wire:click="open({{ $conversation->getKey() }})">
                                <x-ui.avatar :name="$conversation->displayName()" size="sm" />
                                <span class="min-w-0 flex-1">
                                    <span class="flex items-center justify-between gap-2">
                                        <span class="lq-cell-title truncate">{{ $conversation->displayName() }}</span>
                                        <span class="lq-inbox-time">{{ $conversation->last_message_at?->diffForHumans(['short' => true, 'syntax' => \Carbon\CarbonInterface::DIFF_ABSOLUTE]) }}</span>
                                    </span>
                                    <span class="flex items-center justify-between gap-2">
                                        <span class="lq-cell-sub truncate">{{ $conversation->last_message_preview }}</span>
                                        @if ($conversation->unread_count > 0 && ! $selected?->is($conversation))
                                            <span class="lq-count">{{ $conversation->unread_count }}</span>
                                        @elseif (! $conversation->canReply())
                                            <x-heroicon-m-lock-closed class="h-3.5 w-3.5 flex-none lq-muted" title="Reply window closed" />
                                        @endif
                                    </span>
                                </span>
                            </button>
                        </li>
                    @empty
                        <li class="px-6 py-6 text-sm lq-muted">No chat matches your search.</li>
                    @endforelse
                </ul>
            </x-ui.card>

            <x-ui.card flush class="lq-inbox-chat">
                @if ($selected)
                    @livewire(\App\Modules\Meta\Livewire\App\ConversationThread::class, ['conversationId' => $selected->getKey()], key('thread-'.$selected->getKey()))
                    @if ($selected->lead)
                        <div class="lq-thread-lead">
                            <x-ui.status-pill :status="$selected->lead->status" />
                            <span class="text-sm lq-muted">{{ $selected->lead->assignee?->name ? 'Owner: '.$selected->lead->assignee->name : 'Unassigned' }}</span>
                            <x-ui.button size="sm" variant="ghost" class="ml-auto" :href="route('brand.leads.show', ['brand' => $brand->slug, 'lead' => $selected->lead_id])">Open lead</x-ui.button>
                        </div>
                    @endif
                @else
                    <x-ui.empty title="Choose a chat" description="Pick a chat on the left to read it and reply." icon="heroicon-o-chat-bubble-left-right" />
                @endif
            </x-ui.card>
        </div>
    @endif
</div>
