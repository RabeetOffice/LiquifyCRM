{{--
    The chat box customiser (user request, 12 Nov 2026): settings on the left in five parts, a live preview of the website
    with the chat on the right. The preview reads the fields straight from $wire in the browser, so every change shows at
    once and nothing is saved until Save. It draws the launcher and the window as widget.js and the frame do.
--}}
@php
    $tabs = ['setup' => ['Setup', 'heroicon-o-globe-alt'], 'look' => ['Look', 'heroicon-o-swatch'], 'window' => ['Window', 'heroicon-o-window'], 'words' => ['Words', 'heroicon-o-chat-bubble-bottom-center-text'], 'behaviour' => ['Behaviour', 'heroicon-o-adjustments-horizontal']];
    $tabErrors = [
        'setup' => $errors->hasAny(['name', 'domains']),
        'look' => $errors->hasAny(['color', 'accent', 'style', 'position', 'offsetX', 'offsetY']),
        'window' => $errors->hasAny(['theme', 'header', 'corners', 'size']),
        'words' => $errors->hasAny(['title', 'welcome', 'offline', 'consent', 'label', 'onlineSub', 'offlineSub', 'replyTime']),
        'behaviour' => $errors->hasAny(['maxMb', 'showOn', 'greetAfter']),
    ];
    $firstDomain = \App\Modules\Chat\Models\ChatWidget::parseDomains($domains)['domains'][0] ?? null;
@endphp
<div class="lq-designer" x-data="{
        device: 'desktop',
        open: true,
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
                                <span class="lq-tile-art lq-tile-art-{{ $key }}" :style="'--c:' + c + ';--a:' + a"><i></i>@if ($key !== 'bubble')<b></b>@endif</span>
                                <span>{{ $label }}</span>
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
                            <x-ui.input wire:model="label" name="label" :placeholder="$defaults['label']" maxlength="40" />
                        </x-ui.field>
                        <div class="lq-form-grid lq-form-grid-2">
                            <x-ui.field label="When you are here" name="onlineSub">
                                <x-ui.input wire:model="onlineSub" name="onlineSub" :placeholder="$defaults['online_sub']" maxlength="60" />
                            </x-ui.field>
                            <x-ui.field label="When nobody is here" name="offlineSub">
                                <x-ui.input wire:model="offlineSub" name="offlineSub" :placeholder="$defaults['offline_sub']" maxlength="60" />
                            </x-ui.field>
                        </div>
                    </div>
                </section>
                <section class="lq-group">
                    <h2 class="lq-group-title">In the window</h2>
                    <div class="lq-group-body">
                        <x-ui.field label="Title" name="title">
                            <x-ui.input wire:model="title" name="title" :placeholder="$defaults['title']" maxlength="60" />
                        </x-ui.field>
                        <x-ui.field label="Under the title" name="replyTime" help="Shown while somebody of the team is here.">
                            <x-ui.input wire:model="replyTime" name="replyTime" :placeholder="$defaults['reply_time']" maxlength="80" />
                        </x-ui.field>
                        <x-ui.field label="Welcome message" name="welcome" help="The first thing the visitor reads, and the greeting by the button.">
                            <x-ui.textarea wire:model="welcome" name="welcome" rows="2" :placeholder="$defaults['welcome']"></x-ui.textarea>
                        </x-ui.field>
                        <x-ui.field label="When nobody is here" name="offline">
                            <x-ui.textarea wire:model="offline" name="offline" rows="2" :placeholder="$defaults['offline']"></x-ui.textarea>
                        </x-ui.field>
                        <x-ui.field label="Privacy line" name="consent" help="Shown under the welcome message.">
                            <x-ui.textarea wire:model="consent" name="consent" rows="2" :placeholder="$defaults['consent']"></x-ui.textarea>
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
                    </div>
                </section>
                <section class="lq-group">
                    <h2 class="lq-group-title">Getting attention</h2>
                    <div class="lq-group-body">
                        <x-ui.field label="Greeting by the button" name="greetAfter" help="The welcome message pops up beside the button, once a visit, for visitors who have not written yet.">
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
                            <button type="button" role="switch" class="lq-switch" :aria-checked="$wire.askFirst ? 'true' : 'false'" x-on:click="$wire.askFirst = !$wire.askFirst" aria-label="Ask for details first"></button>
                        </div>
                        <div class="lq-group-row lq-group-row-wrap" x-show="$wire.askFirst">
                            @foreach ($prechatFields as $field => $label)
                                <x-ui.checkbox wire:model="fields.{{ $field }}" :label="$label" wire:key="ask-{{ $field }}" />
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
        <div class="lq-designer-stage">
            <div class="lq-dstage-tools">
                <div class="lq-seg" role="radiogroup" aria-label="Preview on">
                    <button type="button" role="radio" class="lq-seg-item" :class="device === 'desktop' && 'lq-seg-active'" x-on:click="device = 'desktop'"><x-heroicon-o-computer-desktop />Computer</button>
                    <button type="button" role="radio" class="lq-seg-item" :class="device === 'phone' && 'lq-seg-active'" x-on:click="device = 'phone'"><x-heroicon-o-device-phone-mobile />Phone</button>
                </div>
                <div class="lq-seg" role="radiogroup" aria-label="Chat">
                    <button type="button" role="radio" class="lq-seg-item" :class="!open && 'lq-seg-active'" x-on:click="open = false">Closed</button>
                    <button type="button" role="radio" class="lq-seg-item" :class="open && 'lq-seg-active'" x-on:click="open = true">Open</button>
                </div>
            </div>

            <div class="lq-dstage" :class="device === 'phone' ? 'lq-dstage-phone' : 'lq-dstage-desktop'">
                <div class="lq-dstage-site" aria-hidden="true">
                    <div class="lq-dstage-chrome"><i></i><i></i><i></i><span>{{ $firstDomain ?? 'your-website.com' }}</span></div>
                    <div class="lq-dstage-page">
                        <div class="lq-dstage-nav" :style="'--c:' + c"><b></b><i></i><i></i><i></i><em></em></div>
                        <div class="lq-dstage-hero"><i></i><i></i><i class="short"></i><em :style="'background:' + c"></em></div>
                        <div class="lq-dstage-cards"><i></i><i></i><i></i></div>
                    </div>

                    {{-- The window --}}
                    <div class="lq-pv-window" x-show="open" x-transition.opacity.scale.95.origin.bottom
                        :class="{ 'lq-pv-dark': dark, 'lq-pv-glass': $wire.header === 'glass' }"
                        :style="device === 'phone'
                            ? 'inset:0;width:auto;height:auto;border-radius:0;'
                            : 'bottom:' + (Math.min($wire.offsetY, 60) + 74) + 'px;' + side + ':' + Math.min($wire.offsetX, 60) + 'px;width:min(' + width + 'px, calc(100% - 24px));border-radius:' + radius + 'px;'">
                        <div class="lq-pv-head" :style="head">
                            <span class="lq-pv-av" :style="'background:' + a + ';color:' + on(a)">
                                @if ($logoUrl)
                                    <img x-show="$wire.logo" src="{{ $logoUrl }}" alt="">
                                @endif
                                <span x-show="!$wire.logo || {{ $logoUrl ? 'false' : 'true' }}" x-text="text($wire.title, 'title').charAt(0).toUpperCase()"></span>
                                <i :style="'box-shadow:0 0 0 2px ' + ($wire.header === 'glass' ? (dark ? '#1c1c1e' : '#fff') : c)"></i>
                            </span>
                            <span class="lq-pv-titles"><strong x-text="text($wire.title, 'title')"></strong><small x-text="text($wire.replyTime, 'reply_time')"></small></span>
                            <span class="lq-pv-x"><x-heroicon-o-x-mark /></span>
                        </div>
                        <div class="lq-pv-thread">
                            <div class="lq-pv-msg"><span x-text="text($wire.welcome, 'welcome')"></span></div>
                            <div class="lq-pv-msg lq-pv-me" :style="'background:' + c + ';color:' + on(c)"><span>Hi, I’d like to publish my first book.</span></div>
                            <div class="lq-pv-msg"><span>Wonderful! What is it about?</span></div>
                            <div class="lq-pv-msg lq-pv-typing"><i></i><i></i><i></i></div>
                            <p class="lq-pv-consent" x-text="text($wire.consent, 'consent')"></p>
                        </div>
                        <div class="lq-pv-composer">
                            <x-heroicon-o-paper-clip />
                            <span>Write a message…</span>
                            <b :style="'background:' + a + ';color:' + on(a)"><x-heroicon-s-paper-airplane /></b>
                        </div>
                    </div>

                    {{-- The greeting, while closed --}}
                    <div class="lq-pv-greet" x-show="!open && $wire.greetAfter > 0" x-transition
                        :style="'bottom:' + (Math.min($wire.offsetY, 60) + 76) + 'px;' + side + ':' + Math.min($wire.offsetX, 60) + 'px;'">
                        <small x-text="text($wire.title, 'title')"></small>
                        <span x-text="text($wire.welcome, 'welcome')"></span>
                    </div>

                    {{-- The button --}}
                    <div class="lq-pv-launcher" x-show="!(open && device === 'phone')"
                        :class="'lq-pv-' + ((open || $wire.style === 'bubble') ? 'round' : $wire.style)"
                        :style="'bottom:' + Math.min($wire.offsetY, 60) + 'px;' + side + ':' + Math.min($wire.offsetX, 60) + 'px;--c:' + c + ';--on:' + on(c) + ';--a:' + a + ';--ona:' + on(a)">
                        <template x-if="open"><x-heroicon-o-chevron-down /></template>
                        <template x-if="!open && $wire.style === 'bubble'"><x-heroicon-o-chat-bubble-left-ellipsis /></template>
                        <template x-if="!open && $wire.style !== 'bubble'">
                            <span class="lq-pv-face">
                                <span class="lq-pv-circle"><x-heroicon-o-chat-bubble-left-ellipsis /><i></i></span>
                                <span class="lq-pv-words">
                                    <small x-show="$wire.style === 'card'" x-text="text($wire.onlineSub, 'online_sub')"></small>
                                    <strong x-text="text($wire.label, 'label')"></strong>
                                    <small x-show="$wire.style === 'pill'" x-text="text($wire.onlineSub, 'online_sub')"></small>
                                </span>
                            </span>
                        </template>
                    </div>
                </div>
            </div>
            <p class="lq-dstage-note">The preview shows the chat as if somebody of the team is here.</p>
        </div>
    </div>
</div>
