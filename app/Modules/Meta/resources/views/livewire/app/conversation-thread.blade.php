<div class="lq-thread" wire:poll.30s.visible data-live-refresh>
    @php
        $ends = $conversation->replyWindowEndsAt();
        $open = $conversation->canReply();
        $previousDay = null;
    @endphp

    <header class="lq-thread-head">
        @if ($inbox)
            <button type="button" class="lq-btn lq-btn-ghost lq-btn-icon lq-thread-back" x-data x-on:click="$dispatch('inbox-back')" title="Back to the chats"><x-heroicon-o-arrow-left /></button>
        @endif
        <x-ui.avatar :name="$conversation->displayName()" tone />
        <div class="min-w-0 flex-1">
            <span class="lq-cell-title truncate">{{ $conversation->displayName() }}</span>
            <span class="lq-cell-sub truncate">Messenger · {{ $conversation->source?->name ?? 'Page removed' }}</span>
        </div>
        @if ($open)
            <x-ui.badge color="success" icon="heroicon-m-clock" title="Messenger lets you reply for 24 hours after this person's last message">Reply within {{ $ends->diffForHumans(['syntax' => \Carbon\CarbonInterface::DIFF_ABSOLUTE, 'parts' => 2, 'short' => true]) }}</x-ui.badge>
        @else
            <x-ui.badge color="warning" icon="heroicon-m-lock-closed">Reply window closed</x-ui.badge>
        @endif
        @if ($leadUrl)
            <a href="{{ $leadUrl }}" class="lq-btn lq-btn-ghost lq-btn-icon lq-thread-leadlink" title="Open lead" wire:navigate><x-heroicon-o-arrow-top-right-on-square /></a>
        @endif
    </header>

    {{-- Stays at the newest message while you are near the bottom; reading older ones is left alone. --}}
    <div
        class="lq-thread-body"
        x-data="{ near: true, stick() { this.$el.scrollTop = this.$el.scrollHeight } }"
        x-init="stick(); new MutationObserver(() => near && stick()).observe($el, { childList: true })"
        x-on:scroll.passive="near = $el.scrollHeight - $el.scrollTop - $el.clientHeight < 140"
    >
        @forelse ($messages as $index => $message)
            @php
                $day = $message->created_at?->toDateString();
                $next = $messages[$index + 1] ?? null;
                $failed = $message->status === \App\Modules\Meta\Enums\MessageStatus::Failed;
                // The last bubble of a run from one side carries the tail and the time.
                $last = $next === null
                    || $next->isOutbound() !== $message->isOutbound()
                    || $next->created_at?->toDateString() !== $day
                    || $next->sent_by !== $message->sent_by;
            @endphp

            @if ($day !== $previousDay)
                <div class="lq-thread-day" wire:key="day-{{ $day }}">
                    <span>{{ match (true) { $message->created_at === null => '', $message->created_at->isToday() => 'Today', $message->created_at->isYesterday() => 'Yesterday', default => $message->created_at->format($message->created_at->isCurrentYear() ? 'l, j F' : 'j F Y') } }}</span>
                </div>
                @php $previousDay = $day; @endphp
            @endif

            <div @class(['lq-msg', 'lq-msg-out' => $message->isOutbound(), 'lq-msg-last' => $last, 'lq-msg-failed' => $failed]) wire:key="message-{{ $message->getKey() }}">
                <div class="lq-bubble" title="{{ $message->created_at?->format('j M Y, H:i') }}">
                    <p class="whitespace-pre-wrap">{{ $message->body }}</p>
                </div>
                @if ($last || $failed)
                    <span class="lq-bubble-meta">
                        @if ($message->isOutbound())
                            {{ $message->sender?->name ?? 'Team' }} · {{ $message->created_at?->format('H:i') }} · {{ $message->status->getLabel() }}
                        @else
                            {{ $message->created_at?->format('H:i') }}
                        @endif
                    </span>
                @endif
                @if ($message->error)
                    <span class="lq-bubble-meta lq-bubble-error">{{ $message->error }}</span>
                @endif
            </div>
        @empty
            <p class="lq-thread-empty">No messages yet.</p>
        @endforelse
    </div>

    @if ($canReply)
        <form wire:submit="send" class="lq-thread-foot">
            @if ($open)
                <div class="lq-composer">
                    <textarea
                        wire:model="reply"
                        name="reply"
                        rows="1"
                        class="lq-composer-input"
                        placeholder="Write a reply…"
                        aria-label="Reply"
                        x-data
                        x-on:input="$el.style.height = 'auto'; $el.style.height = Math.min($el.scrollHeight, 160) + 'px'"
                        x-on:conversation-updated.window="$el.style.height = 'auto'"
                        x-on:keydown.ctrl.enter="$wire.send()"
                        x-on:keydown.meta.enter="$wire.send()"
                    ></textarea>
                    <button type="submit" class="lq-composer-send" title="Send (Ctrl + Enter)" wire:loading.attr="disabled" wire:target="send">
                        <x-heroicon-m-paper-airplane />
                    </button>
                </div>
                <p class="lq-composer-hint"><kbd class="lq-kbd">Ctrl</kbd> <kbd class="lq-kbd">Enter</kbd> sends</p>
            @else
                <p class="lq-composer-locked"><x-heroicon-o-lock-closed /> Messenger only lets a Page reply within 24 hours of the person's last message. You can reply again as soon as they write.</p>
            @endif
            @error('reply')
                <p class="lq-error">{{ $message }}</p>
            @enderror
        </form>
    @endif
</div>
