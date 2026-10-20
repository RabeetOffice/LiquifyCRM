{{-- `sending` holds what was just typed: its bubble shows the moment Send is clicked, and gives way to the real one.
     `bytes` counts the reply as Instagram does (an emoji is four), against `limit` (0: no count shown). --}}
<div class="lq-thread" wire:poll.30s.visible data-live-refresh="chat" x-data="{ sending: [], bytes: 0, limit: {{ $conversation->isInstagram() ? \App\Modules\Meta\Models\Conversation::INSTAGRAM_MAX_BYTES : 0 }} }">
    @php
        $ends = $conversation->replyWindowEndsAt();
        $open = $conversation->canReply();
        $previousDay = null;
        $channel = $conversation->channelLabel();
        $instagram = $conversation->isInstagram();
    @endphp

    <header class="lq-thread-head">
        @if ($inbox)
            <button type="button" class="lq-btn lq-btn-ghost lq-btn-icon lq-thread-back" x-data x-on:click="$dispatch('inbox-back')" title="Back to the chats"><x-heroicon-o-arrow-left /></button>
        @endif
        <x-ui.avatar :name="$conversation->displayName()" :src="$conversation->pictureUrl()" tone />
        <div class="min-w-0 flex-1">
            <span class="lq-cell-title truncate">{{ $conversation->displayName() }}</span>
            <span class="lq-cell-sub lq-channel-line truncate"><x-ui.brand-icon :name="$conversation->channel" /> {{ $channel }} · {{ $conversation->accountName() }}</span>
        </div>
        @if ($open)
            <x-ui.badge color="success" icon="heroicon-m-clock" title="{{ $channel }} lets you reply for 24 hours after this person's last message">Reply within {{ $ends->diffForHumans(['syntax' => \Carbon\CarbonInterface::DIFF_ABSOLUTE, 'parts' => 2, 'short' => true]) }}</x-ui.badge>
        @else
            <x-ui.badge color="warning" icon="heroicon-m-lock-closed">Reply window closed</x-ui.badge>
        @endif
        {{-- On a screen too narrow for the lead beside the chat, its two actions sit here. --}}
        @if ($inbox)
            @if ($canAssign)
                <button type="button" class="lq-btn lq-btn-ghost lq-btn-icon lq-thread-leadlink" x-data x-on:click="$dispatch('inbox-assign')" title="Assign this chat"><x-heroicon-o-user-plus /></button>
            @endif
            @if ($canReply)
                <button type="button" class="lq-btn lq-btn-ghost lq-btn-icon lq-thread-leadlink" x-data x-on:click="$dispatch('inbox-details')" title="Edit details"><x-heroicon-o-pencil-square /></button>
            @endif
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
                {{-- Pictures, videos, voice notes, files and shared posts, shown from Meta's own address. --}}
                @foreach ($message->attachments() as $file)
                    @if ($file['type'] === 'image')
                        <a href="{{ $file['url'] }}" class="lq-attach lq-attach-image" target="_blank" rel="noopener noreferrer" title="Open the picture">
                            <img src="{{ $file['url'] }}" alt="Picture" loading="lazy" referrerpolicy="no-referrer" onerror="this.parentElement.classList.add('lq-attach-gone'); this.replaceWith(document.createTextNode('This picture is no longer kept by {{ $instagram ? 'Instagram' : 'Facebook' }}.'))">
                        </a>
                    @elseif ($file['type'] === 'video')
                        <video class="lq-attach lq-attach-video" controls preload="metadata" src="{{ $file['url'] }}"></video>
                    @elseif ($file['type'] === 'audio')
                        <audio class="lq-attach lq-attach-audio" controls preload="none" src="{{ $file['url'] }}"></audio>
                    @else
                        <a href="{{ $file['url'] }}" class="lq-attach lq-attach-file" target="_blank" rel="noopener noreferrer">
                            @if ($file['type'] === 'link') <x-heroicon-o-link /> @else <x-heroicon-o-paper-clip /> @endif
                            <span class="truncate">{{ $file['name'] }}</span>
                        </a>
                    @endif
                @endforeach

                @unless ($message->isOnlyAttachments())
                    <div class="lq-bubble" title="{{ $message->created_at?->format('j M Y, H:i') }}">
                        <p class="whitespace-pre-wrap">{{ $message->body }}</p>
                    </div>
                @endunless
                @if ($last || $failed)
                    <span class="lq-bubble-meta">
                        @if ($message->isOutbound())
                            {{ $message->isFromFacebook() ? ($instagram ? 'Sent on Instagram' : 'Sent on Facebook') : ($message->sender?->name ?? 'Team') }} · {{ $message->created_at?->format('H:i') }} · {{ $message->status->getLabel() }}
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

        {{-- What was just typed, until the answer brings the real bubble. Livewire leaves this block alone. --}}
        <div class="lq-thread-pending" wire:ignore>
            <template x-for="(text, index) in sending" :key="index">
                <div class="lq-msg lq-msg-out lq-msg-last lq-msg-sending">
                    <div class="lq-bubble"><p class="whitespace-pre-wrap" x-text="text"></p></div>
                    <span class="lq-bubble-meta">Sending…</span>
                </div>
            </template>
        </div>
    </div>

    @if ($canReply)
        <form
            class="lq-thread-foot"
            x-on:submit.prevent="
                const text = ($wire.reply ?? '').trim();
                const box = $el.querySelector('textarea');
                if (text === '' || sending.length > 0 || (limit > 0 && bytes > limit)) return;
                sending.push(text);
                $nextTick(() => {
                    if (box) { box.value = ''; box.style.height = 'auto'; }
                    bytes = 0;
                    const thread = $root.querySelector('.lq-thread-body');
                    if (thread) thread.scrollTop = thread.scrollHeight;
                });
                $wire.send().finally(() => {
                    sending = [];
                    // Not sent (the window closed, Facebook refused): the words go back into the box.
                    if (box && ($wire.reply ?? '') !== '') box.value = $wire.reply;
                    bytes = box ? new TextEncoder().encode(box.value).length : 0;
                });
            "
        >
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
                        x-on:input="$el.style.height = 'auto'; $el.style.height = Math.min($el.scrollHeight, 160) + 'px'; bytes = new TextEncoder().encode($el.value).length"
                        x-on:conversation-updated.window="$el.style.height = 'auto'"
                        x-on:keydown.ctrl.enter.prevent="$el.form.requestSubmit()"
                        x-on:keydown.meta.enter.prevent="$el.form.requestSubmit()"
                    ></textarea>
                    <button type="submit" class="lq-composer-send" title="Send (Ctrl + Enter)" x-bind:disabled="sending.length > 0 || (limit > 0 && bytes > limit)">
                        <x-heroicon-m-paper-airplane />
                    </button>
                </div>
                <p class="lq-composer-hint">
                    <kbd class="lq-kbd">Ctrl</kbd> <kbd class="lq-kbd">Enter</kbd> sends
                    @if ($instagram)
                        <span x-bind:class="{ 'lq-composer-over': bytes > limit }" x-text="bytes > 0 ? '· ' + bytes.toLocaleString() + ' / 1,000' + (bytes > limit ? ': too long for Instagram' : '') : '· Instagram takes up to 1,000 characters'">· Instagram takes up to 1,000 characters</span>
                    @endif
                </p>
            @else
                <p class="lq-composer-locked"><x-heroicon-o-lock-closed /> {{ $instagram ? 'Instagram only lets a business' : 'Messenger only lets a Page' }} reply within 24 hours of the person's last message. You can reply again as soon as they write.</p>
            @endif
            @error('reply')
                <p class="lq-error">{{ $message }}</p>
            @enderror
        </form>
    @elseif ($canTake)
        <div class="lq-thread-foot">
            <p class="lq-composer-locked lq-composer-free">
                <x-heroicon-o-hand-raised />
                <span class="flex-1">Nobody has this chat yet. Take it to reply.</span>
                @if ($inbox)
                    <x-ui.button size="sm" variant="primary" icon="heroicon-o-hand-raised" x-data x-on:click="$dispatch('inbox-take')">Take this chat</x-ui.button>
                @endif
            </p>
        </div>
    @elseif ($owner)
        <div class="lq-thread-foot">
            <p class="lq-composer-locked lq-composer-other"><x-heroicon-o-user /> {{ $owner }} is on this chat, so only they reply.</p>
        </div>
    @endif
</div>
