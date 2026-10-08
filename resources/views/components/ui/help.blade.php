@props(['text'])
{{-- Inline "?" help next to a setting: opens on click, hover or keyboard focus. --}}
<span class="lq-tip" x-data="{ open: false }" x-on:mouseenter="open = true" x-on:mouseleave="open = false" x-on:click.outside="open = false" x-on:keydown.escape="open = false">
    <button type="button" class="lq-tip-btn" x-on:click="open = ! open" x-on:focus="open = true" x-on:blur="open = false" aria-label="Help" x-bind:aria-expanded="open">?</button>
    <span class="lq-tip-bubble" role="tooltip" x-show="open" x-transition.opacity.duration.150ms x-cloak>{{ $text }}</span>
</span>
