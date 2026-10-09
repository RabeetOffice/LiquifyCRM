{{-- Where a lead came from. A website lead shows the website's favicon and name when LeadSites::attach() ran. --}}
@props(['lead'])
@php
    $source = $lead->source_type;
    $site = $lead->relationLoaded('site') ? $lead->getRelation('site') : null;
    $favicon = $site?->faviconUrl();
@endphp
@if ($site)
    <span {{ $attributes->class(['lq-badge', 'lq-badge-'.$source->getColor()]) }} title="{{ $source->getLabel() }}: {{ $site->name }}">
        @if ($favicon)
            <img src="{{ $favicon }}" alt="" class="lq-badge-logo" loading="lazy">
        @else
            @svg($source->getIcon())
        @endif
        <span class="lq-badge-text">{{ $site->name }}</span>
    </span>
@else
    <x-ui.badge :color="$source->getColor()" :icon="$source->getIcon()" {{ $attributes }}>{{ $source->getLabel() }}</x-ui.badge>
@endif
