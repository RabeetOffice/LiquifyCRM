<div class="lq-wide flex flex-col gap-5">
    <x-ui.page-header title="Inbox" subtitle="Messenger chats of your leads. Reply here or from the lead page." />

    @if ($total === 0)
        <x-ui.card>
            <x-ui.empty title="No chats yet" description="When someone writes to a Facebook Page of this brand, the chat appears here and a lead is created in the New queue." icon="heroicon-o-chat-bubble-left-right" />
        </x-ui.card>
    @else
        <div @class(['lq-inbox', 'lq-inbox-open' => $selected !== null, 'lq-inbox-detailed' => $selected?->lead !== null]) wire:poll.15s.visible>
            {{-- The chats --}}
            <aside class="lq-inbox-list" aria-label="Chats">
                <div class="lq-inbox-tools">
                    <label class="lq-search lq-search-block">
                        <x-heroicon-o-magnifying-glass />
                        <input type="search" wire:model.live.debounce.300ms="search" placeholder="Search chats" aria-label="Search chats">
                    </label>
                    <div class="lq-seg" role="tablist" aria-label="Which chats to show">
                        <button type="button" @class(['lq-seg-item', 'lq-seg-active' => $filter === 'all']) wire:click="$set('filter', 'all')" role="tab">All</button>
                        <button type="button" @class(['lq-seg-item', 'lq-seg-active' => $filter === 'unread']) wire:click="$set('filter', 'unread')" role="tab">
                            Unread
                            @if ($unread > 0)
                                <span class="lq-count">{{ $unread }}</span>
                            @endif
                        </button>
                        <button type="button" @class(['lq-seg-item', 'lq-seg-active' => $filter === 'open']) wire:click="$set('filter', 'open')" role="tab" title="Chats you can still answer: the person wrote in the last 24 hours">Can reply</button>
                    </div>
                </div>

                <ul class="lq-inbox-items">
                    @forelse ($conversations as $conversation)
                        @php
                            $active = (bool) $selected?->is($conversation);
                            $fresh = $conversation->unread_count > 0 && ! $active;
                        @endphp
                        <li wire:key="chat-{{ $conversation->getKey() }}">
                            <button type="button" @class(['lq-inbox-item', 'lq-inbox-item-active' => $active, 'lq-inbox-item-unread' => $fresh]) wire:click="open({{ $conversation->getKey() }})" @if ($active) aria-current="true" @endif>
                                <span class="lq-inbox-avatar">
                                    <x-ui.avatar :name="$conversation->displayName()" tone />
                                    <span class="lq-inbox-channel"><x-ui.brand-icon name="messenger" /></span>
                                </span>
                                <span class="min-w-0 flex-1">
                                    <span class="lq-inbox-row">
                                        <span class="lq-inbox-name">{{ $conversation->displayName() }}</span>
                                        <span class="lq-inbox-time" title="{{ $conversation->last_message_at?->format('j M Y, H:i') }}">{{ $conversation->last_message_at?->diffForHumans(['short' => true, 'syntax' => \Carbon\CarbonInterface::DIFF_ABSOLUTE]) }}</span>
                                    </span>
                                    <span class="lq-inbox-row">
                                        <span class="lq-inbox-preview">{{ $conversation->last_message_preview }}</span>
                                        @if ($fresh)
                                            <span class="lq-count">{{ $conversation->unread_count }}</span>
                                        @elseif (! $conversation->canReply())
                                            <x-heroicon-m-lock-closed class="lq-inbox-lock" title="Reply window closed" />
                                        @endif
                                    </span>
                                </span>
                            </button>
                        </li>
                    @empty
                        <li class="lq-inbox-none">
                            {{ $search !== '' ? 'No chat matches your search.' : ($filter === 'unread' ? 'You have read everything.' : 'No chat can be answered right now.') }}
                        </li>
                    @endforelse
                </ul>
            </aside>

            {{-- The open thread --}}
            <section class="lq-inbox-chat" aria-label="Conversation">
                @if ($selected)
                    @livewire(\App\Modules\Meta\Livewire\App\ConversationThread::class, ['conversationId' => $selected->getKey(), 'inbox' => true], key('thread-'.$selected->getKey()))
                @else
                    <x-ui.empty title="Choose a chat" description="Pick a chat on the left to read it and reply." icon="heroicon-o-chat-bubble-left-right" />
                @endif
            </section>

            {{-- The lead behind it --}}
            @if ($selected?->lead)
                @php $lead = $selected->lead; @endphp
                <aside class="lq-inbox-side" aria-label="Lead">
                    <div class="lq-inbox-side-hero">
                        <x-ui.avatar :name="$lead->name" size="lg" tone />
                        <h2 class="lq-inbox-side-name">{{ $lead->name }}</h2>
                        <x-ui.status-pill :status="$lead->status" />
                    </div>

                    <dl class="lq-kv">
                        <div>
                            <dt>Owner</dt>
                            <dd>
                                @if ($lead->assignee)
                                    <span class="lq-person"><x-ui.avatar :name="$lead->assignee->name" :src="$lead->assignee->avatarUrl()" size="xs" /> {{ $lead->assignee->name }}</span>
                                @else
                                    <span class="lq-unassigned">Unassigned</span>
                                @endif
                            </dd>
                        </div>
                        <div>
                            <dt>Department</dt>
                            <dd>{{ $lead->department?->name ?? 'Not sent yet' }}</dd>
                        </div>
                        @if ($lead->phone)
                            <div>
                                <dt>Phone</dt>
                                <dd class="lq-tabular">{{ $fullContact ? $lead->phone : $lead->maskedPhone() }}</dd>
                            </div>
                        @endif
                        @if ($lead->email)
                            <div>
                                <dt>Email</dt>
                                <dd class="break-all">{{ $fullContact ? $lead->email : $lead->maskedEmail() }}</dd>
                            </div>
                        @endif
                        <div>
                            <dt>Follow-up</dt>
                            <dd><x-ui.due :at="$lead->next_follow_up_at" empty="None set" /></dd>
                        </div>
                        <div>
                            <dt>Page</dt>
                            <dd>{{ $selected->source?->name ?? 'Page removed' }}</dd>
                        </div>
                        <div>
                            <dt>First wrote</dt>
                            <dd title="{{ $lead->created_at?->format('j M Y, H:i') }}">{{ $lead->created_at?->diffForHumans() }}</dd>
                        </div>
                    </dl>

                    <x-ui.button block icon="heroicon-o-arrow-top-right-on-square" :href="route('brand.leads.show', ['brand' => $brand->slug, 'lead' => $lead->getKey()])">Open lead</x-ui.button>
                </aside>
            @endif
        </div>
    @endif
</div>
