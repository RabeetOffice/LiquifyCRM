<div class="flex flex-col gap-6" @unless ($showForm) data-live-refresh @endunless>
    @if ($showForm)
        @include("chat::partials.designer")
    @else
    <x-ui.page-header title="Live chat" :subtitle="'The chat box for the websites of '.$brand->name.'. Add one line to a website and visitors can write to you from it.'">
        <x-slot:actions>
            <x-ui.button variant="primary" icon="heroicon-o-plus" wire:click="create">Add live chat</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @if ($widgets->isEmpty())
        <x-ui.card>
            <x-ui.empty title="No live chat yet" description="Add one for this brand's website. You choose the websites it runs on, its colour and what it says. A visitor is not a lead until they give an email address or a phone number." icon="heroicon-o-chat-bubble-oval-left-ellipsis">
                <x-slot:actions><x-ui.button variant="secondary" icon="heroicon-o-plus" wire:click="create">Add the first one</x-ui.button></x-slot:actions>
            </x-ui.empty>
        </x-ui.card>
    @else
        @foreach ($widgets as $widget)
            <x-ui.card :title="$widget->name" wire:key="widget-{{ $widget->getKey() }}">
                <x-slot:actions>
                    <x-ui.badge :color="$widget->is_active ? 'success' : 'gray'">{{ $widget->is_active ? 'On' : 'Off' }}</x-ui.badge>
                    <button type="button" class="lq-btn lq-btn-ghost lq-btn-icon" wire:click="edit({{ $widget->getKey() }})" title="Change" aria-label="Change {{ $widget->name }}"><x-heroicon-o-pencil-square /></button>
                    <button type="button" class="lq-btn lq-btn-ghost lq-btn-icon" wire:click="toggle({{ $widget->getKey() }})" title="{{ $widget->is_active ? 'Switch off' : 'Switch on' }}" aria-label="{{ $widget->is_active ? 'Switch off' : 'Switch on' }} {{ $widget->name }}"><x-heroicon-o-power /></button>
                    <button type="button" class="lq-btn lq-btn-ghost lq-btn-icon lq-btn-icon-danger" wire:click="confirmRemove({{ $widget->getKey() }})" title="Remove" aria-label="Remove {{ $widget->name }}"><x-heroicon-o-trash /></button>
                </x-slot:actions>

                <dl class="lq-kv">
                    <div>
                        <dt>Runs on</dt>
                        <dd>{{ collect($widget->allowed_domains)->join(', ') }}</dd>
                    </div>
                    <div>
                        <dt>Looks</dt>
                        <dd class="flex items-center gap-2"><span style="display: inline-block; width: 1rem; height: 1rem; border-radius: 9999px; box-shadow: inset 0 0 0 1px rgb(0 0 0 / 0.12); background: {{ $widget->look('color') }}"></span> <span style="display: inline-block; width: 1rem; height: 1rem; border-radius: 9999px; box-shadow: inset 0 0 0 1px rgb(0 0 0 / 0.12); background: {{ $widget->accent() }}"></span> {{ $choices['style'][$widget->choice('style')] }} · {{ $widget->look('position') === 'left' ? 'bottom left' : 'bottom right' }} · {{ $choices['theme'][$widget->choice('theme')] }}</dd>
                    </div>
                    <div>
                        <dt>Asks first</dt>
                        <dd>
                            @if ($widget->asksFirst())
                                {{ collect($prechatFields)->filter(fn (string $label, string $field): bool => $widget->asks($field))->join(', ') ?: 'Nothing' }}
                            @else
                                Nothing: the visitor just starts writing
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt>Who sees visitors</dt>
                        <dd>{{ $widget->agents_see_visitors ? 'Managers and Agents' : 'Managers only' }}</dd>
                    </div>
                </dl>

                <x-ui.field label="The line for the website" class="mt-4">
                    <div class="lq-copy" x-data="{ copied: false }">
                        <pre class="lq-log" x-ref="text">{{ $widget->snippet() }}</pre>
                        <x-ui.button size="sm" variant="secondary" icon="heroicon-o-clipboard" x-on:click="navigator.clipboard.writeText($refs.text.innerText); copied = true; setTimeout(() => copied = false, 2000)"><span x-text="copied ? 'Copied' : 'Copy'">Copy</span></x-ui.button>
                    </div>
                </x-ui.field>
                <p class="lq-help mt-3">Paste it into the website's footer, just before the closing body tag, on every page. Once it is there, the chat box appears on the website.</p>
            </x-ui.card>
        @endforeach
    @endif

    @endif

    <x-ui.confirm :confirm="$confirm" />
</div>
