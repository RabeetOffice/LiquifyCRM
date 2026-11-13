{{--
    The chat box customiser (user request, 12 Nov 2026): settings on the left in five parts, a live preview of the website
    with the chat on the right. The preview reads the fields straight from $wire in the browser, so every change shows at
    once and nothing is saved until Save. It draws the launcher and the window as widget.js and the frame do.
--}}
@php
    $tabs = ['setup' => ['Setup', 'heroicon-o-globe-alt'], 'look' => ['Look', 'heroicon-o-swatch'], 'window' => ['Window', 'heroicon-o-window'], 'words' => ['Words', 'heroicon-o-chat-bubble-bottom-center-text'], 'behaviour' => ['Behaviour', 'heroicon-o-adjustments-horizontal']];
    $tabErrors = [
        'setup' => $errors->hasAny(['name', 'domains']),
        'look' => $errors->hasAny(['color', 'accent', 'style', 'stylePhone', 'icon', 'position', 'offsetX', 'offsetY']),
        'window' => $errors->hasAny(['theme', 'header', 'corners', 'size']),
        'words' => $errors->hasAny(['title', 'welcome', 'offline', 'consent', 'label', 'onlineSub', 'offlineSub', 'replyTime', 'teaser']),
        'behaviour' => $errors->hasAny(['maxMb', 'showOn', 'greetAfter', 'motion', 'awayMode', 'grabberArt', 'grabberText', 'grabberYes', 'grabberNo']),
    ];
    $icons = array (
  'chat' => 'M8.625 12a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H8.25m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H12m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 0 1-2.555-.337A5.972 5.972 0 0 1 5.41 20.97a5.969 5.969 0 0 1-.474-.065 4.48 4.48 0 0 0 .978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25Z',
  'chats' => 'M20.25 8.511c.884.284 1.5 1.128 1.5 2.097v4.286c0 1.136-.847 2.1-1.98 2.193-.34.027-.68.052-1.02.072v3.091l-3-3c-1.354 0-2.694-.055-4.02-.163a2.115 2.115 0 0 1-.825-.242m9.345-8.334a2.126 2.126 0 0 0-.476-.095 48.64 48.64 0 0 0-8.048 0c-1.131.094-1.976 1.057-1.976 2.192v4.286c0 .837.46 1.58 1.155 1.951m9.345-8.334V6.637c0-1.621-1.152-3.026-2.76-3.235A48.455 48.455 0 0 0 11.25 3c-2.115 0-4.198.137-6.24.402-1.608.209-2.76 1.614-2.76 3.235v6.226c0 1.621 1.152 3.026 2.76 3.235.577.075 1.157.14 1.74.194V21l4.155-4.155',
  'question' => 'M9.879 7.519c1.171-1.025 3.071-1.025 4.242 0 1.172 1.025 1.172 2.687 0 3.712-.203.179-.43.326-.67.442-.745.361-1.45.999-1.45 1.827v.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 5.25h.008v.008H12v-.008Z',
  'sparkles' => 'M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09ZM18.259 8.715 18 9.75l-.259-1.035a3.375 3.375 0 0 0-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 0 0 2.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 0 0 2.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 0 0-2.456 2.456ZM16.894 20.567 16.5 21.75l-.394-1.183a2.25 2.25 0 0 0-1.423-1.423L13.5 18.75l1.183-.394a2.25 2.25 0 0 0 1.423-1.423l.394-1.183.394 1.183a2.25 2.25 0 0 0 1.423 1.423l1.183.394-1.183.394a2.25 2.25 0 0 0-1.423 1.423Z',
  'hand' => 'M10.05 4.575a1.575 1.575 0 1 0-3.15 0v3m3.15-3v-1.5a1.575 1.575 0 0 1 3.15 0v1.5m-3.15 0 .075 5.925m3.075.75V4.575m0 0a1.575 1.575 0 0 1 3.15 0V15M6.9 7.575a1.575 1.575 0 1 0-3.15 0v8.175a6.75 6.75 0 0 0 6.75 6.75h2.018a5.25 5.25 0 0 0 3.712-1.538l1.732-1.732a5.25 5.25 0 0 0 1.538-3.712l.003-2.024a.668.668 0 0 1 .198-.471 1.575 1.575 0 1 0-2.228-2.228 3.818 3.818 0 0 0-1.12 2.687M6.9 7.575V12m6.27 4.318A4.49 4.49 0 0 1 16.35 15m.002 0h-.002',
  'envelope' => 'M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.916V6.75',
);
    $firstDomain = \App\Modules\Chat\Models\ChatWidget::parseDomains($domains)['domains'][0] ?? null;
@endphp
<div class="lq-designer" x-data="{
        device: 'desktop',
        open: true,
        away: false,
        thumb: '',
        arts: @js(collect($choices['grabber_art'])->all()),
        icons: @js($icons),
        get icon() { return '<svg viewBox=\x270 0 24 24\x27 fill=\x27none\x27 stroke=\x27currentColor\x27 stroke-width=\x271.7\x27 stroke-linecap=\x27round\x27 stroke-linejoin=\x27round\x27><path d=\x27' + (this.icons[$wire.icon] || this.icons.chat) + '\x27/></svg>' },
        get pvStyle() { return this.device === 'phone' && $wire.stylePhone ? $wire.stylePhone : $wire.style },
        get status() { return this.away ? this.text($wire.offlineSub, 'offline_sub') : this.text($wire.onlineSub, 'online_sub') },
        get hidden() {
            if (this.device === 'phone' && $wire.showOn === 'desktop') return 'The chat is not shown on phones.';
            if (this.device === 'desktop' && $wire.showOn === 'phone') return 'The chat is not shown on computers.';
            if (this.away && $wire.awayMode === 'hide') return 'The chat is hidden while nobody is here.';
            return '';
        },
        defaults: @js($defaults),
        hex(v) { return /^#[0-9a-f]{6}$/i.test(v || '') ? v : null },
        on(v) {
            const h = (this.hex(v) || '#f97316').slice(1);
            const l = (0.299 * parseInt(h.slice(0, 2), 16) + 0.587 * parseInt(h.slice(2, 4), 16) + 0.114 * parseInt(h.slice(4, 6), 16)) / 255;
            return l > 0.62 ? '#111111' : '#ffffff';
        },
        get c() { return this.hex($wire.color) || '#f97316' },
        get a() { return this.hex($wire.accent) || this.c },
        get dark() { return $wire.theme === 'dark' },
        get radius() { return { round: 24, standard: 16, square: 8 }[$wire.corners] ?? 16 },
        get width() { return { compact: 340, standard: 380, large: 420 }[$wire.size] ?? 380 },
        get side() { return $wire.position === 'left' ? 'left' : 'right' },
        text(value, key) { return (value || '').trim() || this.defaults[key] },
        get head() {
            if ($wire.header === 'glass') return 'background:' + (this.dark ? 'rgba(28,28,30,.72)' : 'rgba(255,255,255,.72)') + ';color:' + (this.dark ? '#f5f5f7' : '#1d1d1f') + ';border-bottom:1px solid ' + (this.dark ? 'rgba(255,255,255,.1)' : 'rgba(0,0,0,.08)') + ';position:absolute;inset:0 0 auto;';
            if ($wire.header === 'gradient') return 'background:linear-gradient(135deg,' + this.c + ' 0%,color-mix(in srgb,' + this.c + ' 62%,' + this.a + ') 100%);color:' + this.on(this.c) + ';';
            return 'background:' + this.c + ';color:' + this.on(this.c) + ';';
        },
    }">
    {{-- The bar: where you are, and Save --}}
    <div class="lq-designer-bar">
        <button type="button" class="lq-designer-back" wire:click="$set('showForm', false)">
            <x-heroicon-o-chevron-left />
            <span>Live chat</span>
        </button>
        <div class="lq-designer-title">
            <h1>{{ $editing ? $name : 'New chat box' }}</h1>
            <p>Changes show in the preview at once. Visitors see them after you save.</p>
        </div>
        <div class="lq-designer-actions">
            <x-ui.button variant="ghost" wire:click="$set('showForm', false)">Cancel</x-ui.button>
            <x-ui.button variant="primary" icon="heroicon-o-check" wire:click="save" wire:loading.attr="disabled" wire:target="save">{{ $editing ? 'Save' : 'Add live chat' }}</x-ui.button>
        </div>
    </div>

    <div class="lq-designer-grid">
        <form class="lq-designer-panel" wire:submit="save" autocomplete="off">
            <div class="lq-seg lq-designer-tabs" role="tablist" aria-label="Parts of the chat box">
                @foreach ($tabs as $key => [$label, $icon])
                    <button type="button" role="tab" class="lq-seg-item" :class="$wire.tab === '{{ $key }}' && 'lq-seg-active'" :aria-selected="$wire.tab === '{{ $key }}'" x-on:click="$wire.tab = '{{ $key }}'">
                        <x-dynamic-component :component="$icon" />
                        <span>{{ $label }}</span>
                        @if ($tabErrors[$key])
                            <i class="lq-designer-tab-error" aria-label="Needs a look"></i>
                        @endif
                    </button>
                @endforeach
            </div>

            {{-- ---------------------------------------------------------------- Setup --}}
            <div class="lq-designer-part" x-show="$wire.tab === 'setup'" x-cloak>
                <section class="lq-group">
                    <h2 class="lq-group-title">Where it runs</h2>
                    <div class="lq-group-body">
                        <x-ui.field label="Name" name="name" required help="Only your team sees this.">
                            <x-ui.input wire:model="name" name="name" />
                        </x-ui.field>
                        <x-ui.field label="Websites" name="domains" required help="One a line, like example.com. The chat works only on these, their pages and subdomains, over https.">
                            <x-ui.textarea wire:model.blur="domains" name="domains" rows="3" placeholder="dublinpublishingpress.ie"></x-ui.textarea>
                        </x-ui.field>
                    </div>
                </section>
                <section class="lq-group">
                    <h2 class="lq-group-title">Who answers</h2>
                    <div class="lq-group-body lq-group-rows">
                        <div class="lq-group-row">
                            <div><strong>Chat is on</strong><span>Off hides the chat on the website. Chats already started stay.</span></div>
                            <button type="button" role="switch" class="lq-switch" :aria-checked="$wire.active ? 'true' : 'false'" x-on:click="$wire.active = !$wire.active" aria-label="Chat is on"></button>
                        </div>
                        <div class="lq-group-row">
                            <div><strong>Agents see new visitors</strong><span>Visitors who gave no email or phone yet. Off: only Managers see them.</span></div>
                            <button type="button" role="switch" class="lq-switch" :aria-checked="$wire.agentsSeeVisitors ? 'true' : 'false'" x-on:click="$wire.agentsSeeVisitors = !$wire.agentsSeeVisitors" aria-label="Agents see new visitors"></button>
                        </div>
                    </div>
                </section>
            </div>

            {{-- ---------------------------------------------------------------- Look --}}
            <div class="lq-designer-part" x-show="$wire.tab === 'look'" x-cloak>
                <section class="lq-match">
                    <div class="lq-match-text">
                        <x-heroicon-o-sparkles />
                        <div>
                            <strong>Match the website</strong>
                            <span>{{ $firstDomain ? 'We read the colours '.$firstDomain.' uses and dress the chat in them.' : 'List the website under Setup first.' }}</span>
                        </div>
                    </div>
                    <x-ui.button variant="secondary" size="sm" icon="heroicon-o-eye-dropper" wire:click="matchWebsite" wire:loading.attr="disabled" wire:target="matchWebsite" :disabled="! $firstDomain">
                        <span wire:loading.remove wire:target="matchWebsite">Match colours</span>
                        <span wire:loading wire:target="matchWebsite">Reading the website…</span>
                    </x-ui.button>
                </section>

                <section class="lq-group">
                    <h2 class="lq-group-title">Colours</h2>
                    <div class="lq-group-body">
                        @foreach (['color' => ['Main colour', 'The button, the header and the visitor’s messages.'], 'accent' => ['Accent', 'The icon’s circle on the pill, the send button and highlights.']] as $prop => [$label, $hint])
                            <div class="lq-colour-row">
                                <div class="lq-colour-label"><strong>{{ $label }}</strong><span>{{ $hint }}</span></div>
                                <div class="lq-colour-pick">
                                    @foreach (collect($palette)->merge($prop === 'color' ? ['#111827', '#1d4ed8', '#0f766e', '#f97316'] : ['#f59e0b', '#ef4444', '#22c55e', '#6366f1'])->unique()->take(7) as $swatch)
                                        <button type="button" class="lq-swatch" style="--swatch: {{ $swatch }}" :class="($wire.{{ $prop }} || '').toLowerCase() === '{{ strtolower($swatch) }}' && 'lq-swatch-on'" x-on:click="$wire.{{ $prop }} = '{{ strtolower($swatch) }}'" aria-label="{{ $label }} {{ $swatch }}" title="{{ $swatch }}"></button>
                                    @endforeach
                                    <label class="lq-swatch lq-swatch-custom" title="Any colour" :style="'--swatch:' + ({{ $prop === 'color' ? 'c' : 'a' }})">
                                        <input type="color" :value="{{ $prop === 'color' ? 'c' : 'a' }}" x-on:input="$wire.{{ $prop }} = $event.target.value" aria-label="{{ $label }}: any colour">
                                        <x-heroicon-o-plus />
                                    </label>
                                    <input type="text" class="lq-input lq-hex" wire:model="{{ $prop }}" maxlength="7" spellcheck="false" placeholder="{{ $prop === 'accent' ? 'Same as main' : '#f97316' }}" aria-label="{{ $label }} code">
                                </div>
                                @error($prop)<p class="lq-error">{{ $message }}</p>@enderror
                            </div>
                        @endforeach
                        @if ($paletteFrom)
                            <p class="lq-help">The first swatches are the colours of {{ $paletteFrom }}.</p>
                        @endif
                    </div>
                </section>

                <section class="lq-group">
                    <h2 class="lq-group-title">The button on the website</h2>
                    <div class="lq-tiles">
                        @foreach ($choices['style'] as $key => $label)
                            <button type="button" class="lq-tile" :class="$wire.style === '{{ $key }}' && 'lq-tile-on'" x-on:click="$wire.style = '{{ $key }}'" :aria-pressed="$wire.style === '{{ $key }}'">
                                <span class="lq-tile-art lq-tile-art-{{ $key }}" :style="'--c:' + c + ';--a:' + a"><i></i>@if (! in_array($key, ['bubble', 'square'], true))<b></b>@endif</span>
                                <span>{{ $label }}</span>
                            </button>
                        @endforeach
                    </div>
                    <div class="lq-group-body">
                        <x-ui.field label="On phones" name="stylePhone" help="A phone has less room: many websites use the round button there and the pill on computers.">
                            <x-ui.select wire:model="stylePhone" name="stylePhone" x-on:change="device = 'phone'; open = false">
                                @foreach ($choices['style_phone'] as $key => $label)
                                    <option value="{{ $key }}">{{ $label }}</option>
                                @endforeach
                            </x-ui.select>
                        </x-ui.field>
                    </div>
                </section>

                <section class="lq-group">
                    <h2 class="lq-group-title">Icon</h2>
                    <div class="lq-icons">
                        @foreach ($choices['icon'] as $key => $label)
                            <button type="button" class="lq-icon-pick" :class="$wire.icon === '{{ $key }}' && 'lq-icon-pick-on'" x-on:click="$wire.icon = '{{ $key }}'; open = false" :style="'--c:' + c + ';--on:' + on(c)" title="{{ $label }}" aria-label="Icon: {{ $label }}">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $icons[$key] }}"/></svg>
                            </button>
                        @endforeach
                    </div>
                </section>

                <section class="lq-group">
                    <h2 class="lq-group-title">Place</h2>
                    <div class="lq-group-body">
                        <div class="lq-seg" role="radiogroup" aria-label="Side of the page">
                            @foreach (['left' => 'Bottom left', 'right' => 'Bottom right'] as $key => $label)
                                <button type="button" role="radio" class="lq-seg-item" :class="$wire.position === '{{ $key }}' && 'lq-seg-active'" :aria-checked="$wire.position === '{{ $key }}'" x-on:click="$wire.position = '{{ $key }}'">{{ $label }}</button>
                            @endforeach
                        </div>
                        @foreach (['offsetX' => 'From the side', 'offsetY' => 'From the bottom'] as $prop => $label)
                            <div class="lq-range-row">
                                <span>{{ $label }}</span>
                                <input type="range" min="0" max="160" step="2" wire:model="{{ $prop }}" x-on:input="$wire.{{ $prop }} = parseInt($event.target.value, 10)" aria-label="{{ $label }}">
                                <output x-text="$wire.{{ $prop }} + ' px'"></output>
                            </div>
                        @endforeach
                        <p class="lq-help">Move it up when the website already has a button in that corner, like WhatsApp.</p>
                    </div>
                </section>
            </div>

            {{-- ---------------------------------------------------------------- Window --}}
            <div class="lq-designer-part" x-show="$wire.tab === 'window'" x-cloak>
                <section class="lq-group">
                    <h2 class="lq-group-title">Appearance</h2>
                    <div class="lq-group-body">
                        <div class="lq-seg" role="radiogroup" aria-label="Light or dark">
                            @foreach ($choices['theme'] as $key => $label)
                                <button type="button" role="radio" class="lq-seg-item" :class="$wire.theme === '{{ $key }}' && 'lq-seg-active'" :aria-checked="$wire.theme === '{{ $key }}'" x-on:click="$wire.theme = '{{ $key }}'">
                                    <x-dynamic-component :component="['light' => 'heroicon-o-sun', 'dark' => 'heroicon-o-moon', 'auto' => 'heroicon-o-computer-desktop'][$key]" />{{ $label }}
                                </button>
                            @endforeach
                        </div>
                        <p class="lq-help">“Follow the visitor” turns dark when their phone or computer is set to dark.</p>
                    </div>
                </section>
                <section class="lq-group">
                    <h2 class="lq-group-title">Header</h2>
                    <div class="lq-tiles">
                        @foreach ($choices['header'] as $key => $label)
                            <button type="button" class="lq-tile" :class="$wire.header === '{{ $key }}' && 'lq-tile-on'" x-on:click="$wire.header = '{{ $key }}'" :aria-pressed="$wire.header === '{{ $key }}'">
                                <span class="lq-tile-art lq-tile-head lq-tile-head-{{ $key }}" :style="'--c:' + c + ';--a:' + a"><i></i></span>
                                <span>{{ $label }}</span>
                            </button>
                        @endforeach
                    </div>
                </section>
                <section class="lq-group">
                    <h2 class="lq-group-title">Voice calls</h2>
                    <div class="lq-group-body lq-group-rows">
                        <div class="lq-group-row">
                            <div><strong>Visitors can call you</strong><span>A phone button in the chat box's header while somebody of the team is here. The call rings in LiquifyCRM for everybody who works website visitors; the first to answer talks to the visitor, browser to browser. Calls are not recorded. If the button does not show on a website, its .htaccess forbids the microphone: change <code>microphone=()</code> to <code>microphone=(self)</code> in its Permissions-Policy line.</span></div>
                            <button type="button" role="switch" class="lq-switch" :aria-checked="$wire.voice ? 'true' : 'false'" x-on:click="$wire.voice = !$wire.voice; open = true; away = false" aria-label="Visitors can call you"></button>
                        </div>
                    </div>
                </section>
                <section class="lq-group">
                    <h2 class="lq-group-title">Rating</h2>
                    <div class="lq-group-body lq-group-rows">
                        <div class="lq-group-row">
                            <div><strong>Visitors rate the chat</strong><span>A thumb up and a thumb down in the header once the team has answered. The team sees the rating in the chat.</span></div>
                            <button type="button" role="switch" class="lq-switch" :aria-checked="$wire.rating ? 'true' : 'false'" x-on:click="$wire.rating = !$wire.rating; open = true; away = false" aria-label="Visitors rate the chat"></button>
                        </div>
                    </div>
                </section>
                <section class="lq-group">
                    <h2 class="lq-group-title">Shape and size</h2>
                    <div class="lq-group-body">
                        <div class="lq-seg" role="radiogroup" aria-label="Corners">
                            @foreach ($choices['corners'] as $key => $label)
                                <button type="button" role="radio" class="lq-seg-item" :class="$wire.corners === '{{ $key }}' && 'lq-seg-active'" :aria-checked="$wire.corners === '{{ $key }}'" x-on:click="$wire.corners = '{{ $key }}'">{{ $label }} corners</button>
                            @endforeach
                        </div>
                        <div class="lq-seg" role="radiogroup" aria-label="Width">
                            @foreach ($choices['size'] as $key => $label)
                                <button type="button" role="radio" class="lq-seg-item" :class="$wire.size === '{{ $key }}' && 'lq-seg-active'" :aria-checked="$wire.size === '{{ $key }}'" x-on:click="$wire.size = '{{ $key }}'">{{ $label }}</button>
                            @endforeach
                        </div>
                        <p class="lq-help">On a phone the chat always fills the screen.</p>
                    </div>
                </section>
                <section class="lq-group">
                    <div class="lq-group-body lq-group-rows">
                        <div class="lq-group-row">
                            <div><strong>Show the brand’s mark</strong><span>{{ $logoUrl ? 'Its icon in the header, beside the title.' : 'This brand has no logo yet: the first letter of the title shows instead.' }}</span></div>
                            <button type="button" role="switch" class="lq-switch" :aria-checked="$wire.logo ? 'true' : 'false'" x-on:click="$wire.logo = !$wire.logo" aria-label="Show the brand’s mark"></button>
                        </div>
                    </div>
                </section>
            </div>

            {{-- ---------------------------------------------------------------- Words --}}
            <div class="lq-designer-part" x-show="$wire.tab === 'words'" x-cloak>
                <section class="lq-group">
                    <h2 class="lq-group-title">On the button</h2>
                    <div class="lq-group-body">
                        <x-ui.field label="Words" name="label" help="Shown on the pill and the card.">
                            <x-ui.input wire:model="label" name="label" :placeholder="$defaults['label']" maxlength="40" x-on:focus="open = false; away = false" />
                        </x-ui.field>
                        <div class="lq-form-grid lq-form-grid-2">
                            <x-ui.field label="When you are here" name="onlineSub">
                                <x-ui.input wire:model="onlineSub" name="onlineSub" :placeholder="$defaults['online_sub']" maxlength="60" x-on:focus="open = false; away = false" />
                            </x-ui.field>
                            <x-ui.field label="When nobody is here" name="offlineSub">
                                <x-ui.input wire:model="offlineSub" name="offlineSub" :placeholder="$defaults['offline_sub']" maxlength="60" x-on:focus="open = false; away = true" />
                            </x-ui.field>
                        </div>
                        <x-ui.field label="Teaser above the button" name="teaser" help="A few words that float above the closed button, like “Need help? 👋”, until the visitor opens the chat or closes them. Empty: none.">
                            <x-ui.input wire:model="teaser" name="teaser" placeholder="Need help? 👋" maxlength="40" x-on:focus="open = false; away = false" />
                        </x-ui.field>
                    </div>
                </section>
                <section class="lq-group">
                    <h2 class="lq-group-title">In the window</h2>
                    <div class="lq-group-body">
                        <x-ui.field label="Title" name="title">
                            <x-ui.input wire:model="title" name="title" :placeholder="$defaults['title']" maxlength="60" x-on:focus="open = true" />
                        </x-ui.field>
                        <x-ui.field label="Under the title" name="replyTime" help="Shown while somebody of the team is here.">
                            <x-ui.input wire:model="replyTime" name="replyTime" :placeholder="$defaults['reply_time']" maxlength="80" x-on:focus="open = true; away = false" />
                        </x-ui.field>
                        <x-ui.field label="Welcome message" name="welcome" help="The first thing the visitor reads, and the greeting by the button.">
                            <x-ui.textarea wire:model="welcome" name="welcome" rows="2" :placeholder="$defaults['welcome']" x-on:focus="open = true; away = false"></x-ui.textarea>
                        </x-ui.field>
                        <x-ui.field label="When nobody is here" name="offline">
                            <x-ui.textarea wire:model="offline" name="offline" rows="2" :placeholder="$defaults['offline']" x-on:focus="open = true; away = true"></x-ui.textarea>
                        </x-ui.field>
                        <x-ui.field label="Privacy line" name="consent" help="Shown under the welcome message.">
                            <x-ui.textarea wire:model="consent" name="consent" rows="2" :placeholder="$defaults['consent']" x-on:focus="open = true; away = false"></x-ui.textarea>
                        </x-ui.field>
                    </div>
                </section>
            </div>

            {{-- ---------------------------------------------------------------- Behaviour --}}
            <div class="lq-designer-part" x-show="$wire.tab === 'behaviour'" x-cloak>
                <section class="lq-group">
                    <h2 class="lq-group-title">Where it shows</h2>
                    <div class="lq-group-body">
                        <div class="lq-seg" role="radiogroup" aria-label="Devices">
                            @foreach ($choices['show_on'] as $key => $label)
                                <button type="button" role="radio" class="lq-seg-item" :class="$wire.showOn === '{{ $key }}' && 'lq-seg-active'" :aria-checked="$wire.showOn === '{{ $key }}'" x-on:click="$wire.showOn = '{{ $key }}'">{{ $label }}</button>
                            @endforeach
                        </div>
                        <x-ui.field label="When nobody of the team is here" name="awayMode">
                            <div class="lq-seg" role="radiogroup" aria-label="When nobody is here">
                                @foreach ($choices['away_mode'] as $key => $label)
                                    <button type="button" role="radio" class="lq-seg-item" :class="$wire.awayMode === '{{ $key }}' && 'lq-seg-active'" :aria-checked="$wire.awayMode === '{{ $key }}'" x-on:click="$wire.awayMode = '{{ $key }}'; away = true">{{ $label }}</button>
                                @endforeach
                            </div>
                        </x-ui.field>
                        <p class="lq-help">Hidden, the button comes back by itself when somebody of the team opens LiquifyCRM. A visitor with a chat under way still sees it.</p>
                    </div>
                </section>
                <section class="lq-group">
                    <h2 class="lq-group-title">Getting attention</h2>
                    <div class="lq-group-body">
                        <x-ui.field label="Movement of the button" name="motion" help="Every few seconds while the chat is closed. Visitors who asked their phone or computer for less motion see it still.">
                            <div class="lq-seg" role="radiogroup" aria-label="Movement of the button">
                                @foreach ($choices['effect'] as $key => $label)
                                    <button type="button" role="radio" class="lq-seg-item" :class="$wire.motion === '{{ $key }}' && 'lq-seg-active'" :aria-checked="$wire.motion === '{{ $key }}'" x-on:click="$wire.motion = '{{ $key }}'; open = false">{{ $label }}</button>
                                @endforeach
                            </div>
                        </x-ui.field>
                        <div class="lq-grabber-set">
                            <div class="lq-group-row">
                                <div><strong>Attention grabber</strong><span>A card above the button with a picture, a question and two answers. “Yes” opens the chat, the other puts it away for a day.</span></div>
                                <button type="button" role="switch" class="lq-switch" :aria-checked="$wire.grabber ? 'true' : 'false'" x-on:click="$wire.grabber = !$wire.grabber; open = false; away = false" aria-label="Attention grabber"></button>
                            </div>
                            <div class="lq-grabber-fields" x-show="$wire.grabber">
                                <div class="lq-emoji-picks" role="radiogroup" aria-label="Picture">
                                    @foreach ($choices['grabber_art'] as $key => $emoji)
                                        <button type="button" role="radio" class="lq-emoji-pick" :class="$wire.grabberArt === '{{ $key }}' && 'lq-emoji-pick-on'" :aria-checked="$wire.grabberArt === '{{ $key }}'" x-on:click="$wire.grabberArt = '{{ $key }}'; open = false" title="{{ $key === 'none' ? 'No picture' : ucfirst($key) }}">{{ $emoji !== '' ? $emoji : '—' }}</button>
                                    @endforeach
                                </div>
                                <x-ui.field label="Question" name="grabberText">
                                    <x-ui.input wire:model="grabberText" name="grabberText" :placeholder="$defaults['grabber_text']" maxlength="120" x-on:focus="open = false" />
                                </x-ui.field>
                                <div class="lq-form-grid lq-form-grid-2">
                                    <x-ui.field label="Yes button" name="grabberYes">
                                        <x-ui.input wire:model="grabberYes" name="grabberYes" :placeholder="$defaults['grabber_yes']" maxlength="24" x-on:focus="open = false" />
                                    </x-ui.field>
                                    <x-ui.field label="Other button" name="grabberNo">
                                        <x-ui.input wire:model="grabberNo" name="grabberNo" :placeholder="$defaults['grabber_no']" maxlength="24" x-on:focus="open = false" />
                                    </x-ui.field>
                                </div>
                            </div>
                        </div>
                        <x-ui.field label="Greeting by the button" name="greetAfter" help="When the grabber is on, it shows after this time (4 seconds when “Never”). Otherwise the welcome message pops up beside the button, once a visit, for visitors who have not written yet.">
                            <x-ui.select wire:model="greetAfter" name="greetAfter">
                                <option value="0">Never</option>
                                @foreach ([3, 5, 10, 20, 30, 60] as $seconds)
                                    <option value="{{ $seconds }}">After {{ $seconds }} seconds</option>
                                @endforeach
                            </x-ui.select>
                        </x-ui.field>
                    </div>
                    <div class="lq-group-body lq-group-rows">
                        <div class="lq-group-row">
                            <div><strong>Sound for answers</strong><span>A soft chime for the visitor when an answer comes while the chat is closed.</span></div>
                            <button type="button" role="switch" class="lq-switch" :aria-checked="$wire.sound ? 'true' : 'false'" x-on:click="$wire.sound = !$wire.sound" aria-label="Sound for answers"></button>
                        </div>
                        <div class="lq-group-row">
                            <div><strong>Live visitors</strong><span>Every half minute the button tells us which page is open (without anything after a ?), its title and where the person came from. Kept 30 days.</span></div>
                            <button type="button" role="switch" class="lq-switch" :aria-checked="$wire.track ? 'true' : 'false'" x-on:click="$wire.track = !$wire.track" aria-label="Live visitors"></button>
                        </div>
                    </div>
                </section>
                <section class="lq-group">
                    <h2 class="lq-group-title">Before the chat</h2>
                    <div class="lq-group-body lq-group-rows">
                        <div class="lq-group-row">
                            <div><strong>Ask for details first</strong><span>A visitor becomes a lead only once they give an email address or a phone number.</span></div>
                            <button type="button" role="switch" class="lq-switch" :aria-checked="$wire.askFirst ? 'true' : 'false'" x-on:click="$wire.askFirst = !$wire.askFirst; open = true; away = false" aria-label="Ask for details first"></button>
                        </div>
                        <div class="lq-group-row lq-group-row-wrap lq-chip-checks" x-show="$wire.askFirst">
                            @foreach ($prechatFields as $field => $label)
                                <x-ui.checkbox wire:model="fields.{{ $field }}" :label="$label" wire:key="ask-{{ $field }}" x-on:change="open = true; away = false" />
                            @endforeach
                        </div>
                    </div>
                    <div class="lq-group-body">
                        <x-ui.field label="Largest file (MB)" name="maxMb" help="Pictures, PDF, Word documents and text files, at most 25 MB.">
                            <x-ui.input type="number" min="1" max="25" wire:model="maxMb" name="maxMb" />
                        </x-ui.field>
                    </div>
                </section>
            </div>
        </form>

        {{-- ---------------------------------------------------------------- Preview --}}
        {{-- Click the button to open the chat, the cross to close it, the teaser or greeting to open it, as a visitor would. --}}
        {{-- Drawn by Alpine alone: a Livewire redraw would undo what x-show hid. --}}
        <div class="lq-designer-stage" wire:ignore>
            <div class="lq-dstage-tools">
                <div class="lq-seg" role="radiogroup" aria-label="Preview on">
                    <button type="button" role="radio" class="lq-seg-item" :class="device === 'desktop' && 'lq-seg-active'" x-on:click="device = 'desktop'"><x-heroicon-o-computer-desktop />Computer</button>
                    <button type="button" role="radio" class="lq-seg-item" :class="device === 'phone' && 'lq-seg-active'" x-on:click="device = 'phone'"><x-heroicon-o-device-phone-mobile />Phone</button>
                </div>
                <div class="lq-seg" role="radiogroup" aria-label="Team">
                    <button type="button" role="radio" class="lq-seg-item" :class="!away && 'lq-seg-active'" x-on:click="away = false"><i class="lq-dstage-dot lq-dstage-dot-on"></i>Online</button>
                    <button type="button" role="radio" class="lq-seg-item" :class="away && 'lq-seg-active'" x-on:click="away = true"><i class="lq-dstage-dot"></i>Away</button>
                </div>
                <div class="lq-seg" role="radiogroup" aria-label="Chat">
                    <button type="button" role="radio" class="lq-seg-item" :class="!open && 'lq-seg-active'" x-on:click="open = false">Closed</button>
                    <button type="button" role="radio" class="lq-seg-item" :class="open && 'lq-seg-active'" x-on:click="open = true">Open</button>
                </div>
            </div>

            <div class="lq-dstage" :class="device === 'phone' ? 'lq-dstage-phone' : 'lq-dstage-desktop'">
                <div class="lq-dstage-site">
                    <div class="lq-dstage-island" aria-hidden="true"></div>
                    <div class="lq-dstage-chrome" aria-hidden="true"><i></i><i></i><i></i><span>{{ $firstDomain ?? 'your-website.com' }}</span></div>
                    <div class="lq-dstage-page" aria-hidden="true">
                        <div class="lq-dstage-nav" :style="'--c:' + c"><b></b><i></i><i></i><i></i><em></em></div>
                        <div class="lq-dstage-hero"><i></i><i></i><i class="short"></i><em :style="'background:' + c"></em></div>
                        <div class="lq-dstage-cards"><i></i><i></i><i></i></div>
                    </div>

                    <template x-if="hidden">
                        <p class="lq-dstage-hidden"><x-heroicon-o-eye-slash /> <span x-text="hidden"></span></p>
                    </template>

                    {{-- The window --}}
                    {{-- x-show sits on a wrapper: the :style below would otherwise wipe the display:none it sets. --}}
                    <div class="lq-pv-wrap" x-show="open && !hidden">
                    <div class="lq-pv-window"
                        :class="{ 'lq-pv-dark': dark, 'lq-pv-glass': $wire.header === 'glass', 'lq-pv-onphone': device === 'phone' }"
                        :style="device === 'phone'
                            ? 'inset:0;width:auto;height:auto;max-height:none;border-radius:0;--pv-c:' + c + ';--pv-a:' + a + ';'
                            : 'bottom:' + (Math.min($wire.offsetY, 60) + 74) + 'px;' + side + ':' + Math.min($wire.offsetX, 60) + 'px;width:min(' + width + 'px, calc(100% - 24px));border-radius:' + radius + 'px;--pv-radius:' + radius + 'px;' + '--pv-c:' + c + ';--pv-a:' + a + ';'">
                        <div class="lq-pv-head" :style="head">
                            <span class="lq-pv-av" :style="'background:' + a + ';color:' + on(a)">
                                @if ($logoUrl)
                                    <img x-show="$wire.logo" src="{{ $logoUrl }}" alt="">
                                @endif
                                <span x-show="!$wire.logo || {{ $logoUrl ? 'false' : 'true' }}" x-text="text($wire.title, 'title').charAt(0).toUpperCase()"></span>
                                <i :style="'background:' + (away ? '#a1a1a6' : '#30d158') + ';box-shadow:0 0 0 2px ' + ($wire.header === 'glass' ? (dark ? '#1c1c1e' : '#fff') : c)"></i>
                            </span>
                            <span class="lq-pv-titles"><strong x-text="text($wire.title, 'title')"></strong><small x-text="away ? 'Away right now. Leave a message.' : text($wire.replyTime, 'reply_time')"></small></span>
                            <span class="lq-pv-rate" x-show="$wire.voice && !away"><button type="button" aria-label="Voice call"><x-heroicon-o-phone /></button></span><span class="lq-pv-rate" x-show="$wire.rating && !away && !$wire.askFirst"><button type="button" :class="thumb === 'up' && 'lq-pv-rate-on'" x-on:click="thumb = thumb === 'up' ? '' : 'up'" aria-label="Good"><x-heroicon-o-hand-thumb-up /></button><button type="button" :class="thumb === 'down' && 'lq-pv-rate-on'" x-on:click="thumb = thumb === 'down' ? '' : 'down'" aria-label="Bad"><x-heroicon-o-hand-thumb-down /></button></span>
                            <button type="button" class="lq-pv-x" x-on:click="open = false" aria-label="Close the chat in the preview"><x-heroicon-o-x-mark /></button>
                        </div>

                        <div class="lq-pv-thread">
                            {{-- Nobody here: the form for a message --}}
                            <template x-if="away">
                                <div class="lq-pv-form">
                                    <strong>We are away right now</strong>
                                    <p x-text="text($wire.offline, 'offline')"></p>
                                    <span class="lq-pv-field">Name</span>
                                    <span class="lq-pv-field">Email</span>
                                    <span class="lq-pv-field">Phone number</span>
                                    <span class="lq-pv-field lq-pv-field-tall">Your message</span>
                                    <b :style="'background:' + a + ';color:' + on(a)">Send message</b>
                                </div>
                            </template>

                            {{-- Details first --}}
                            <template x-if="!away && $wire.askFirst">
                                <div class="lq-pv-stack">
                                    <div class="lq-pv-msg"><span x-text="text($wire.welcome, 'welcome')"></span></div>
                                    <div class="lq-pv-form">
                                        <strong>Before we start</strong>
                                        <p>So we can get back to you if the chat is cut off.</p>
                                        @foreach ($prechatFields as $field => $label)
                                            <span class="lq-pv-field {{ $field === 'message' ? 'lq-pv-field-tall' : '' }}" x-show="$wire.fields.{{ $field }}">{{ $label }}</span>
                                        @endforeach
                                        <b :style="'background:' + a + ';color:' + on(a)">Start the chat</b>
                                    </div>
                                    <p class="lq-pv-consent" x-text="text($wire.consent, 'consent')"></p>
                                </div>
                            </template>

                            {{-- A chat under way --}}
                            <template x-if="!away && !$wire.askFirst">
                                <div class="lq-pv-stack">
                                    <div class="lq-pv-msg"><span x-text="text($wire.welcome, 'welcome')"></span></div>
                                    <div class="lq-pv-msg lq-pv-me" :style="'background:' + c + ';color:' + on(c)"><span>Hi, I’d like to publish my first book.</span></div>
                                    <div class="lq-pv-msg"><span>Wonderful! What is it about?</span></div>
                                    <div class="lq-pv-msg lq-pv-typing"><i></i><i></i><i></i></div>
                                    <p class="lq-pv-consent" x-text="text($wire.consent, 'consent')"></p>
                                </div>
                            </template>
                        </div>
                        <div class="lq-pv-composer" x-show="!away && !$wire.askFirst">
                            <x-heroicon-o-paper-clip />
                            <span>Write a message…</span>
                            <b :style="'background:' + a + ';color:' + on(a)"><x-heroicon-s-paper-airplane /></b>
                        </div>
                    </div>
                    </div>

                    {{-- The attention grabber, while closed --}}
                    <div class="lq-pv-wrap" x-show="!open && !hidden && $wire.grabber">
                        <div class="lq-pv-grabber" :style="'bottom:' + (pvStyle === 'bar' ? 64 : Math.min($wire.offsetY, 60) + 76) + 'px;' + side + ':' + Math.min($wire.offsetX, 60) + 'px;--c:' + c + ';--a:' + a + ';--ona:' + on(a)">
                            <div class="lq-pv-grabber-art" x-show="$wire.grabberArt !== 'none'" x-text="arts[$wire.grabberArt] || '👋'"></div>
                            <div class="lq-pv-grabber-body">
                                <p x-text="text($wire.grabberText, 'grabber_text')"></p>
                                <div><button type="button" class="lq-pv-grabber-yes" x-on:click="open = true" x-text="text($wire.grabberYes, 'grabber_yes')"></button><button type="button" class="lq-pv-grabber-no" x-on:click="open = false" x-text="text($wire.grabberNo, 'grabber_no')"></button></div>
                            </div>
                            <i aria-hidden="true">×</i>
                        </div>
                    </div>

                    {{-- The teaser and the greeting, while closed --}}
                    <div class="lq-pv-wrap" x-show="!open && !hidden && !$wire.grabber && ($wire.teaser || '').trim() !== '' && pvStyle !== 'tab'">
                    <button type="button" class="lq-pv-teaser"
                        :style="'bottom:' + (pvStyle === 'bar' ? 64 : Math.min($wire.offsetY, 60) + 72) + 'px;' + side + ':' + Math.min($wire.offsetX, 60) + 'px;'"
                        x-on:click="open = true"><span x-text="$wire.teaser"></span><i>×</i></button>
                    </div>
                    <div class="lq-pv-wrap" x-show="!open && !hidden && !$wire.grabber && $wire.greetAfter > 0 && ($wire.teaser || '').trim() === ''">
                    <button type="button" class="lq-pv-greet"
                        :style="'bottom:' + (pvStyle === 'bar' ? 64 : Math.min($wire.offsetY, 60) + 76) + 'px;' + side + ':' + Math.min($wire.offsetX, 60) + 'px;'"
                        x-on:click="open = true">
                        <small x-text="text($wire.title, 'title')"></small>
                        <span x-text="text($wire.welcome, 'welcome')"></span>
                    </button>
                    </div>

                    {{-- The button --}}
                    <div class="lq-pv-wrap" x-show="!hidden && !(open && device === 'phone')">
                    <button type="button" class="lq-pv-launcher" x-on:click="open = !open"
                        :aria-label="open ? 'Close the chat in the preview' : 'Open the chat in the preview'"
                        :class="['lq-pv-' + (open || pvStyle === 'bubble' ? 'round' : pvStyle), (!open && $wire.motion !== 'none') ? 'lq-pv-fx-' + $wire.motion : '', side === 'left' ? 'lq-pv-left' : '']"
                        :style="(pvStyle === 'tab' && !open ? 'top:50%;' + side + ':0;' : 'bottom:' + (pvStyle === 'bar' && !open ? 0 : Math.min($wire.offsetY, 60)) + 'px;' + side + ':' + Math.min($wire.offsetX, 60) + 'px;') + '--c:' + c + ';--on:' + on(c) + ';--a:' + a + ';--ona:' + on(a) + ';--dot:' + (away ? '#a1a1a6' : '#30d158')">
                        <template x-if="open"><x-heroicon-o-chevron-down /></template>
                        <template x-if="!open && (pvStyle === 'bubble' || pvStyle === 'square')"><span class="lq-pv-icon" x-html="icon"></span></template>
                        <template x-if="!open && (pvStyle === 'pill' || pvStyle === 'card' || pvStyle === 'glass')">
                            <span class="lq-pv-face">
                                <span class="lq-pv-circle"><span class="lq-pv-icon" x-html="icon"></span><i></i></span>
                                <span class="lq-pv-words">
                                    <small x-show="pvStyle === 'card'" x-text="status"></small>
                                    <strong x-text="text($wire.label, 'label')"></strong>
                                    <small x-show="pvStyle === 'pill' || pvStyle === 'glass'" x-text="status"></small>
                                </span>
                            </span>
                        </template>
                        <template x-if="!open && pvStyle === 'outline'">
                            <span class="lq-pv-face"><span class="lq-pv-icon" x-html="icon"></span><strong x-text="text($wire.label, 'label')"></strong></span>
                        </template>
                        <template x-if="!open && pvStyle === 'bar'">
                            <span class="lq-pv-face lq-pv-barface"><i></i><span class="lq-pv-words"><strong x-text="text($wire.label, 'label')"></strong><small x-text="status"></small></span><x-heroicon-o-chevron-up /></span>
                        </template>
                        <template x-if="!open && pvStyle === 'tab'">
                            <span class="lq-pv-face lq-pv-tabface"><span class="lq-pv-icon" x-html="icon"></span><strong x-text="text($wire.label, 'label')"></strong></span>
                        </template>
                    </button>
                    </div>
                </div>
            </div>
            <p class="lq-dstage-note">Click the button in the preview to open and close the chat, as a visitor would.</p>
        </div>
    </div>
</div>
