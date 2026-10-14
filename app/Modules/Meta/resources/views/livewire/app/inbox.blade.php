<div class="lq-wide flex flex-col gap-5" wire:poll.60s.visible data-live-refresh>
    <x-ui.page-header title="Inbox" subtitle="Messenger and Instagram chats of your leads. Reply here or from the lead page." />

    @if ($total === 0)
        <x-ui.card>
            <x-ui.empty title="No chats yet" description="When someone writes to a Facebook Page of this brand, or sends a direct message to its Instagram account, the chat appears here and a lead is created in the New queue." icon="heroicon-o-chat-bubble-left-right" />
        </x-ui.card>
    @else
        <div @class(['lq-inbox', 'lq-inbox-open' => $selected !== null, 'lq-inbox-detailed' => $selected?->lead !== null])>
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
                    @if ($hasInstagram)
                        <div class="lq-seg" role="tablist" aria-label="Which channel">
                            <button type="button" @class(['lq-seg-item', 'lq-seg-active' => $channel === '']) wire:click="$set('channel', '')" role="tab">Every channel</button>
                            <button type="button" @class(['lq-seg-item', 'lq-seg-active' => $channel === 'messenger']) wire:click="$set('channel', 'messenger')" role="tab"><x-ui.brand-icon name="messenger" /> Messenger</button>
                            <button type="button" @class(['lq-seg-item', 'lq-seg-active' => $channel === 'instagram']) wire:click="$set('channel', 'instagram')" role="tab"><x-ui.brand-icon name="instagram" /> Instagram</button>
                        </div>
                    @endif
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
                                    <x-ui.avatar :name="$conversation->displayName()" :src="$conversation->pictureUrl()" tone />
                                    <span @class(['lq-inbox-channel', 'lq-inbox-channel-instagram' => $conversation->isInstagram()]) title="{{ $conversation->channelLabel() }}"><x-ui.brand-icon :name="$conversation->channel" /></span>
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
                            {{ $search !== '' ? 'No chat matches your search.' : ($filter === 'unread' ? 'You have read everything.' : ($filter === 'open' ? 'No chat can be answered right now.' : 'No '.($channel === 'instagram' ? 'Instagram' : 'Messenger').' chats yet.')) }}
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
                        <x-ui.avatar :name="$lead->name" :src="$selected->pictureUrl()" size="lg" tone />
                        <h2 class="lq-inbox-side-name">{{ $lead->name }}</h2>
                        <x-ui.status-pill :status="$lead->status" />
                        @if ($canUpdate)
                            @php $service = $selected->isInstagram() ? 'Instagram' : 'Facebook'; @endphp
                            <button type="button" class="lq-inbox-refresh" wire:click="refreshProfile" wire:loading.attr="disabled" wire:target="refreshProfile" title="Ask {{ $service }} again for this person's name and picture">
                                <x-heroicon-m-arrow-path wire:loading.class="animate-spin" wire:target="refreshProfile" /> Name and picture from {{ $service }}
                            </button>
                        @endif
                    </div>

                    {{-- Hand the chat to someone without leaving it. --}}
                    @if ($canAssign)
                        <label class="lq-inbox-assign">
                            <span class="lq-inbox-assign-label"><x-heroicon-o-user-plus /> Assigned to</span>
                            <select class="lq-input lq-input-sm" aria-label="Assign this lead to" wire:change="assignLead($event.target.value)" wire:key="assign-{{ $lead->getKey() }}-{{ $lead->assigned_to ?? 'none' }}" wire:loading.attr="disabled" wire:target="assignLead">
                                <option value="" @selected($lead->assigned_to === null)>Nobody yet</option>
                                @if ($lead->assignee && ! array_key_exists($lead->assigned_to, $agentOptions))
                                    <option value="{{ $lead->assigned_to }}" selected disabled>{{ $lead->assignee->name }}</option>
                                @endif
                                @foreach ($agentOptions as $id => $name)
                                    <option value="{{ $id }}" @selected($lead->assigned_to === $id)>{{ $name }}</option>
                                @endforeach
                            </select>
                        </label>
                    @endif

                    <dl class="lq-kv">
                        @unless ($canAssign)
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
                        @endunless
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
                        @foreach (\App\Modules\Leads\Actions\UpdateLeadDetails::EXTRAS as $key => $label)
                            @if (filled($lead->fields[$key] ?? null))
                                <div>
                                    <dt>{{ $label }}</dt>
                                    <dd class="whitespace-pre-line">{{ $lead->fields[$key] }}</dd>
                                </div>
                            @endif
                        @endforeach
                        <div>
                            <dt>Follow-up</dt>
                            <dd><x-ui.due :at="$lead->next_follow_up_at" empty="None set" /></dd>
                        </div>
                        @if ($selected->isInstagram())
                            @if ($selected->handle())
                                <div>
                                    <dt>Instagram</dt>
                                    <dd>
                                        @if ($selected->profileUrl())
                                            <a href="{{ $selected->profileUrl() }}" class="lq-link" target="_blank" rel="noopener noreferrer">{{ '@'.$selected->handle() }}</a>
                                        @else
                                            {{ '@'.$selected->handle() }}
                                        @endif
                                    </dd>
                                </div>
                            @endif
                            <div>
                                <dt>Wrote to</dt>
                                <dd>{{ $selected->accountName() }}</dd>
                            </div>
                        @else
                            <div>
                                <dt>Page</dt>
                                <dd>{{ $selected->accountName() }}</dd>
                            </div>
                        @endif
                        <div>
                            <dt>First wrote</dt>
                            <dd title="{{ $lead->created_at?->format('j M Y, H:i') }}">{{ $lead->created_at?->diffForHumans() }}</dd>
                        </div>
                    </dl>

                    <div class="lq-inbox-side-actions">
                        @if ($canUpdate)
                            <x-ui.button block icon="heroicon-o-pencil-square" wire:click="openDetails">Edit details</x-ui.button>
                        @endif
                        <x-ui.button block variant="ghost" icon="heroicon-o-arrow-top-right-on-square" :href="route('brand.leads.show', ['brand' => $brand->slug, 'lead' => $lead->getKey()])">Open lead</x-ui.button>
                        @if ($selected->facebookInboxUrl())
                            @if ($selected->isInstagram())
                                <a href="{{ $selected->facebookInboxUrl() }}" class="lq-btn lq-btn-ghost lq-btn-block" target="_blank" rel="noopener noreferrer" title="The Page's inbox in Meta Business Suite, where its Instagram messages are too.">
                                    <x-ui.brand-icon name="instagram" /> Inbox in Meta Business Suite
                                </a>
                            @else
                                <a href="{{ $selected->facebookInboxUrl() }}" class="lq-btn lq-btn-ghost lq-btn-block" target="_blank" rel="noopener noreferrer" title="Facebook does not share a link to a person's profile. Their profile is one click away in the Page's own inbox.">
                                    <x-ui.brand-icon name="facebook" /> Page inbox on Facebook
                                </a>
                            @endif
                        @endif
                    </div>
                </aside>
            @endif
        </div>

        @include('leads::livewire.app.partials.lead-details')

        <x-ui.modal model="showAssign" title="Assign this chat" description="The lead and its chat go to this person." size="sm">
            <form wire:submit="assignFromDialog" class="lq-form" id="inbox-assign-form">
                <x-ui.field label="Agent" name="assignTo">
                    <x-ui.select wire:model="assignTo" name="assignTo" placeholder="Nobody yet">
                        @foreach ($agentOptions as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>
            </form>
            <x-slot:footer>
                <x-ui.button variant="ghost" wire:click="$set('showAssign', false)">Cancel</x-ui.button>
                <x-ui.button type="submit" form="inbox-assign-form" variant="primary">Assign</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
