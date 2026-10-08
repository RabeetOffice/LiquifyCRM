<div class="lq-thread" wire:poll.10s>
    @php
        $ends = $conversation->replyWindowEndsAt();
        $open = $conversation->canReply();
    @endphp

    <div class="lq-thread-head">
        <div class="min-w-0">
            <span class="lq-cell-title">{{ $conversation->displayName() }}</span>
            <span class="lq-cell-sub">Messenger · {{ $conversation->source?->name ?? 'Page removed' }}</span>
        </div>
        @if ($open)
            <x-ui.badge color="success" icon="heroicon-m-clock" title="Messenger lets you reply for 24 hours after this person's last message">Reply within {{ $ends->diffForHumans(['syntax' => \Carbon\CarbonInterface::DIFF_ABSOLUTE, 'parts' => 2, 'short' => true]) }}</x-ui.badge>
        @else
            <x-ui.badge color="warning" icon="heroicon-m-lock-closed">Reply window closed</x-ui.badge>
        @endif
    </div>

    <div class="lq-thread-body" x-data x-init="$el.scrollTop = $el.scrollHeight">
        @forelse ($messages as $message)
            <div @class(['lq-bubble', 'lq-bubble-out' => $message->isOutbound(), 'lq-bubble-failed' => $message->status === \App\Modules\Meta\Enums\MessageStatus::Failed]) wire:key="message-{{ $message->getKey() }}">
                <p class="whitespace-pre-wrap">{{ $message->body }}</p>
                <span class="lq-bubble-meta" title="{{ $message->created_at?->format('j M Y, H:i') }}">
                    @if ($message->isOutbound())
                        {{ $message->sender?->name ?? 'Team' }} · {{ $message->status->getLabel() }} ·
                    @endif
                    {{ $message->created_at?->diffForHumans() }}
                </span>
                @if ($message->error)
                    <span class="lq-bubble-meta">{{ $message->error }}</span>
                @endif
            </div>
        @empty
            <p class="lq-help">No messages yet.</p>
        @endforelse
    </div>

    @if ($canReply)
        <form wire:submit="send" class="lq-thread-foot">
            @if ($open)
                <div class="flex items-end gap-2">
                    <x-ui.textarea wire:model="reply" name="reply" rows="2" placeholder="Write a reply…" class="flex-1" x-data x-on:keydown.ctrl.enter="$wire.send()" />
                    <x-ui.button type="submit" variant="secondary" icon="heroicon-o-paper-airplane" wire:loading.attr="disabled" wire:target="send">Send</x-ui.button>
                </div>
            @else
                <p class="lq-help">Messenger only lets a Page reply within 24 hours of the person's last message. You can reply again as soon as they write.</p>
            @endif
            @error('reply')
                <p class="lq-error">{{ $message }}</p>
            @enderror
        </form>
    @endif
</div>
