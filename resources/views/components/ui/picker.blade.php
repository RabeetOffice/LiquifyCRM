{{-- A choice from a long list, with a search box (time zones). `model` is the Livewire property it reads and writes;
     each of `options` is {value, label, hint, search}; the `pinned` values are offered before anything is typed.
     Everything happens in the browser: a choice travels with the next request, like wire:model. --}}
@props(['model', 'options' => [], 'pinned' => [], 'name' => null, 'placeholder' => 'Search…', 'more' => null])
<div class="lq-picker" wire:ignore x-data="picker({ model: @js($model), options: @js($options), pinned: @js($pinned) })" @keydown.escape.stop="close(true)" @click.outside="close()">
    <button type="button" class="lq-input lq-picker-btn" x-ref="button" @if ($name) id="{{ $name }}" @endif @click="toggle()" @keydown.arrow-down.prevent="show()" aria-haspopup="listbox" :aria-expanded="open.toString()">
        <span class="lq-picker-value">
            <span class="truncate" x-text="current ? current.label : value"></span>
            <span class="lq-picker-hint" x-show="current" x-text="current?.hint"></span>
        </span>
        <x-heroicon-m-chevron-up-down class="lq-picker-caret" />
    </button>

    <div class="lq-menu lq-picker-menu" x-show="open" x-cloak>
        <div class="lq-picker-search">
            <x-heroicon-o-magnifying-glass />
            <input type="text" x-ref="search" x-model="query" placeholder="{{ $placeholder }}" aria-label="{{ $placeholder }}" autocomplete="off" spellcheck="false"
                @input="active = 0" @keydown.arrow-down.prevent="move(1)" @keydown.arrow-up.prevent="move(-1)" @keydown.enter.prevent="choose(shown[active])">
        </div>

        <ul class="lq-picker-list" role="listbox" x-ref="list">
            <template x-for="(option, index) in shown" :key="option.value">
                <li class="lq-picker-option" role="option" :class="{ 'lq-picker-active': index === active, 'lq-picker-chosen': option.value === value }" :aria-selected="(option.value === value).toString()" @click="choose(option)" @mousemove="active = index">
                    <span class="truncate" x-text="option.label"></span>
                    <span class="lq-picker-hint" x-text="option.hint"></span>
                    <x-heroicon-m-check class="lq-picker-tick" x-show="option.value === value" />
                </li>
            </template>
        </ul>

        <p class="lq-picker-note" x-show="shown.length === 0">Nothing matches. Try a city or a country.</p>
        @if ($more)
            <p class="lq-picker-note" x-show="query.trim() === ''">{{ $more }}</p>
        @endif
    </div>
</div>
