<div class="flex flex-col gap-6" wire:poll.15s.visible>
    <x-ui.page-header title="Live visitors" :subtitle="'Who is on the websites of '.$brand->name.' right now. Open one to see the pages they read, or invite them to chat.'" />

    @php $count = $sessions->count(); @endphp

    <x-ui.card flush class="lq-list">
        <div class="lq-viewbar">
            <div class="lq-views">
                <span class="lq-view lq-view-active">On the website now <span class="lq-view-count">{{ $count }}</span></span>
            </div>
            <div class="lq-viewbar-end"><span class="lq-help">Updates every few seconds</span></div>
        </div>

        @if ($sessions->isEmpty())
            <x-ui.empty title="Nobody is on the website right now" description="When somebody opens a page of the website, they show up here for as long as the page is open. If you expected somebody, check that the chat box is on the site and that Live visitors is switched on under Chat boxes." icon="heroicon-o-eye" />
        @else
            <div class="lq-table-wrap">
                <table class="lq-table">
                    <thead>
                        <tr>
                            <th>Visitor</th>
                            <th>On the page</th>
                            <th class="lq-col-wide">Came from</th>
                            <th>On site</th>
                            <th class="lq-col-wide">Visits</th>
                            <th>Chat</th>
                            <th class="lq-th-end"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($sessions as $session)
                            @php $chatId = $chats[$session->getKey()] ?? null; @endphp
                            <tr wire:key="session-{{ $session->getKey() }}">
                                <td class="lq-td-person">
                                    <span class="lq-person">
                                        <x-ui.avatar :name="$session->displayName()" size="sm" tone />
                                        <span class="min-w-0">
                                            <span class="lq-cell-title truncate">{{ $session->displayName() }}</span>
                                            <span class="lq-cell-sub truncate lq-where lq-where-row" title="{{ \App\Modules\Chat\Support\VisitorLocation::label($session->city, $session->region, $session->country) }}{{ $session->ip ? ' · '.$session->ip : '' }}{{ $session->isp ? ' · '.$session->isp : '' }} · {{ trim(($session->browser ?? '').' · '.ucfirst((string) $session->device), ' ·') }}">@include('chat::partials.flag', ['code' => $session->country]) {{ $session->city ?? (\App\Modules\Chat\Support\VisitorLocation::label(null, null, $session->country) ?: 'Place not known') }}@php $vpn = \App\Modules\Chat\Support\VisitorLocation::vpn($session->is_proxy, $session->isp, $session->country, $session->getAttribute('browser_tz')); @endphp @if ($vpn['vpn']) <x-ui.badge color="danger" :title="$vpn['real'] ? 'Their computer is set to '.\App\Modules\Chat\Support\VisitorLocation::label(null, null, $vpn['real']).' time' : 'A VPN, proxy or data centre address'">VPN detected</x-ui.badge>@endif</span>
                                        </span>
                                    </span>
                                </td>
                                <td>
                                    <span class="lq-cell-title truncate" title="{{ $session->current_url }}">{{ $session->path() }}</span>
                                    <span class="lq-cell-sub truncate">{{ $session->current_title ?? '' }}</span>
                                </td>
                                <td class="lq-col-wide">{{ $session->referrer ?? 'Direct or the same website' }}</td>
                                <td title="Since {{ $session->visit_started_at?->format('H:i') }} UTC">{{ $session->visit_started_at?->diffForHumans(['syntax' => \Carbon\CarbonInterface::DIFF_ABSOLUTE, 'short' => true]) ?? '—' }}</td>
                                <td class="lq-col-wide lq-tabular">{{ $session->visits_count }}</td>
                                <td>
                                    @if ($chatId)
                                        <x-ui.badge color="success">Chatting</x-ui.badge>
                                    @else
                                        <span class="lq-muted">Not chatting</span>
                                    @endif
                                </td>
                                <td class="lq-td-end">
                                    <div class="lq-row-tools">
                                        <button type="button" class="lq-btn lq-btn-ghost lq-btn-sm" wire:click="toggle({{ $session->getKey() }})" aria-expanded="{{ $open === $session->getKey() ? 'true' : 'false' }}">
                                            <x-heroicon-o-queue-list /> Pages
                                        </button>
                                        @if ($chatId)
                                            <a href="{{ $chatsUrl }}?chat={{ $chatId }}" class="lq-btn lq-btn-secondary lq-btn-sm" wire:navigate>
                                                <x-heroicon-o-chat-bubble-left-right /> Open chat
                                            </a>
                                        @else
                                            <button type="button" class="lq-btn lq-btn-secondary lq-btn-sm" wire:click="invite({{ $session->getKey() }})">
                                                <x-heroicon-o-chat-bubble-left-ellipsis /> Start chat
                                            </button>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                            @if ($open === $session->getKey())
                                <tr wire:key="trail-{{ $session->getKey() }}" class="lq-row-detail">
                                    <td colspan="7">
                                        @include('chat::partials.trail', ['trail' => $trail, 'now' => $session])
                                    </td>
                                </tr>
                            @endif
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-ui.card>

    <x-ui.modal model="showInvite" title="Start a chat" description="Your first message shows by the chat button on their screen within a few seconds. You go straight to the chat, where their answer appears." size="sm">
        <form wire:submit="sendInvitation" class="lq-form" id="invite-form">
            <x-ui.field label="Your words" name="invitation" required help="Short and friendly. Up to 300 characters.">
                <x-ui.textarea wire:model="invitation" name="invitation" rows="3" maxlength="300"></x-ui.textarea>
            </x-ui.field>
        </form>
        <x-slot:footer>
            <x-ui.button variant="ghost" wire:click="$set('showInvite', false)">Cancel</x-ui.button>
            <x-ui.button type="submit" form="invite-form" variant="primary" wire:loading.attr="disabled" wire:target="sendInvitation">Send and open the chat</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</div>
