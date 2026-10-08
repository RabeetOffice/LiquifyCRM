@props(['site'])
@php
    $state = $site->connectionState();
@endphp
@switch($state)
    @case('connected')
        <x-ui.badge color="success" icon="heroicon-m-check-circle">Connected</x-ui.badge>
        @break
    @case('quiet')
        <x-ui.badge color="warning" icon="heroicon-m-exclamation-triangle">Quiet for {{ (int) config('liquify.sites.quiet_hours', 48) }} hours</x-ui.badge>
        @break
    @case('paused')
        <x-ui.badge color="gray" icon="heroicon-m-pause">Paused</x-ui.badge>
        @break
    @default
        <x-ui.badge color="gray" icon="heroicon-m-clock">Waiting for the first lead</x-ui.badge>
@endswitch
