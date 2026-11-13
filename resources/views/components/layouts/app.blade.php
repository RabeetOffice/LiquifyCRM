<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @class(['dark' => ($_COOKIE['lq_theme'] ?? '') === 'dark'])>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ isset($title) && $title !== '' ? $title.' · ' : '' }}LiquifyCRM</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="48x48">
    <link rel="icon" href="{{ asset('images/brand/favicon-96.png') }}" type="image/png" sizes="96x96">
    <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('images/brand/icon-180.png') }}">
    <link rel="manifest" href="{{ asset('manifest.webmanifest') }}">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="LiquifyCRM">
    <meta name="theme-color" media="(prefers-color-scheme: light)" content="#f4f4f2">
    <meta name="theme-color" media="(prefers-color-scheme: dark)" content="#0b0a09">
    @auth
        {{-- The public key for desktop alerts with LiquifyCRM closed (Web Push); absent until "Update system". --}}
        @php
            $pushKey = \App\Modules\Core\Support\WebPush::publicKey();
        @endphp
        @if ($pushKey !== null)
            <meta name="lq-push-key" content="{{ $pushKey }}">
        @endif
    @endauth
    <script>
        // Apply the saved theme before the first paint so nothing flashes.
        (function () {
            try {
                var mode = localStorage.getItem('theme') || 'system';
                var dark = mode === 'dark' || (mode === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
                document.documentElement.classList.toggle('dark', dark);
            } catch (e) {}
        })();
    </script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body
    class="lq-body"
    x-data
    x-bind:class="{ 'lq-collapsed': ! $store.sidebar.open, 'lq-mobile-open': $store.sidebar.mobile }"
>
    @include('partials.sidebar')

    <div class="lq-overlay" x-show="$store.sidebar.mobile" x-on:click="$store.sidebar.closeMobile()" x-cloak></div>

    <div class="lq-main">
        @include('partials.topbar')
        @include('partials.update-bar')

        {{-- Shown by app.js when the site has deployed since this page was opened (data-live-build below). --}}
        <div class="lq-updatebar" role="status" data-new-version hidden>
            <x-heroicon-o-arrow-path class="lq-updatebar-icon" />
            <span class="min-w-0 flex-1" data-new-version-text><strong>LiquifyCRM has been updated.</strong> Reload the page to use the new version.</span>
            <span class="min-w-0 flex-1" data-new-version-failed hidden><strong>That did not go through: this page is from before an update.</strong> Copy what you typed, then reload. A note you were writing on a lead comes back by itself.</span>
            <button type="button" class="lq-btn lq-btn-secondary lq-btn-sm" onclick="location.reload()">Reload</button>
        </div>

        {{-- data-live: what this page shows to GET /api/live, which app.js asks every few seconds.
             data-live-at / data-live-chat-at: the brand's and its chats' stamps this page was drawn with. A page
             fetched earlier (hovered link, back button) is reloaded at once when something changed since.
             data-live-build: the code this page was drawn with (Live::build). --}}
        @php
            $liveUser = auth()->user();
            $liveBrand = app(\App\Modules\Core\Support\CurrentBrand::class)->id();
            $liveEvery = $liveUser instanceof \App\Modules\Auth\Models\User && $liveBrand === null && ($liveUser->isGlobalAdmin() || $liveUser->isWebsiteLeads());
            $liveStamps = $liveUser instanceof \App\Modules\Auth\Models\User ? \App\Modules\Core\Support\Live::stamps($liveBrand, (int) $liveUser->getKey(), $liveEvery) : null;
        @endphp
        <main class="lq-content" id="main-content" @if ($liveStamps !== null) data-live="{{ \App\Modules\Core\Support\Live::token((int) $liveUser->getKey(), $liveBrand, $liveEvery) }}" data-live-at="{{ $liveStamps['b'] }}" data-live-chat-at="{{ $liveStamps['c'] }}" data-live-build="{{ \App\Modules\Core\Support\Live::build() }}" @endif @if ($liveStamps !== null && $liveBrand !== null && \App\Modules\Chat\Support\Realtime::enabled()) data-realtime="{{ route('brand.realtime', ['brand' => app(\App\Modules\Core\Support\CurrentBrand::class)->get()?->slug]) }}" @endif>
            {{ $slot }}
        </main>
    </div>

    <x-ui.toasts />

    @auth
        {{-- Voice calls from the website chat boxes (docs/CALLS_PLAN.md): a ringing card on whatever screen is open, and
             the call itself. Kept across wire:navigate, so a call goes on while the person opens another screen. --}}
        @if (\App\Modules\Chat\Support\LiveChat::workReady())
            @persist('call-desk')
                <div class="lq-calls" x-data="callDesk" x-cloak>
                    <audio autoplay playsinline hidden></audio>
                    {{-- Website chats waiting for an answer (it rings until one is opened) and visitors who just arrived. --}}
                    <div class="lq-call-group" x-data="chatDesk">
                        <template x-for="visitor in visitors" :key="visitor.key">
                            <div class="lq-call lq-call-visitor" role="status" x-on:click="see(visitor)">
                                <span class="lq-call-icon lq-call-icon-visitor"><x-heroicon-s-eye /></span>
                                <div class="lq-call-who">
                                    <strong x-text="'New visitor on ' + (visitor.brand || 'the website')"></strong>
                                    <span x-text="visitor.line"></span>
                                </div>
                                <button type="button" class="lq-btn lq-btn-ghost lq-btn-sm" x-on:click.stop="dropVisitor(visitor.key)">Dismiss</button>
                                <button type="button" class="lq-btn lq-btn-secondary lq-btn-sm" x-on:click.stop="see(visitor)">See visitors</button>
                            </div>
                        </template>
                        <template x-for="chat in chats" :key="chat.id">
                            <div class="lq-call lq-call-chat" role="alertdialog" aria-label="New chat message" x-on:click="open(chat)">
                                <span class="lq-call-icon lq-call-icon-chat"><x-heroicon-s-chat-bubble-oval-left-ellipsis /></span>
                                <div class="lq-call-who">
                                    <strong x-text="chat.name + (chat.count > 1 ? ' (' + chat.count + ')' : '') + (chat.brand ? ' · ' + chat.brand : '')"></strong>
                                    <span x-text="chat.text || 'Sent a file'"></span>
                                </div>
                                <button type="button" class="lq-btn lq-btn-ghost lq-btn-sm" x-on:click.stop="putAside(chat)">Later</button>
                                <button type="button" class="lq-btn lq-btn-primary lq-btn-sm" x-on:click.stop="open(chat)">Open chat</button>
                            </div>
                        </template>
                    </div>
                    <template x-for="call in ringing" :key="call.id">
                        <div class="lq-call lq-call-ringing" role="alertdialog" aria-label="Incoming call">
                            <span class="lq-call-icon"><x-heroicon-s-phone-arrow-down-left /></span>
                            <div class="lq-call-who">
                                <strong x-text="call.name"></strong>
                                <span x-text="'Calling from ' + (call.brand || 'the website')"></span>
                            </div>
                            <button type="button" class="lq-btn lq-btn-ghost lq-btn-sm" x-on:click="dismiss(call)">Ignore</button>
                            <button type="button" class="lq-btn lq-btn-primary lq-btn-sm" x-on:click="accept(call)" x-bind:disabled="busy || current !== null">Answer</button>
                        </div>
                    </template>
                    <div class="lq-call" x-show="current !== null" role="status">
                        <span class="lq-call-icon lq-call-icon-live"><x-heroicon-s-phone /></span>
                        <div class="lq-call-who">
                            <strong x-text="current?.name"></strong>
                            <span x-text="current?.talking ? clock : 'Connecting…'"></span>
                        </div>
                        <a class="lq-btn lq-btn-ghost lq-btn-sm" x-bind:href="current?.url" wire:navigate>Open chat</a>
                        <button type="button" class="lq-btn lq-btn-secondary lq-btn-sm" x-on:click="toggleMute()" x-bind:aria-pressed="muted ? 'true' : 'false'" x-text="muted ? 'Unmute' : 'Mute'"></button>
                        <button type="button" class="lq-btn lq-btn-danger lq-btn-sm" x-on:click="hangUp()">Hang up</button>
                    </div>
                    <div class="lq-call lq-call-note" x-show="notice !== ''" x-text="notice" role="status"></div>
                </div>
            @endpersist
        @endif
    @endauth

    @auth
        @livewire(\App\Modules\Core\Livewire\WelcomeTour::class)
    @endauth

    @livewireScriptConfig
</body>
</html>
