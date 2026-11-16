{{-- How ready to buy a lead sounds, as the assistant rated it (LeadInsights). $lead; $all shows Cold too (the lead's own
     screen); lists show only Hot and Warm, so a tag there always means "ring this one". --}}
@php
    $heat = \App\Modules\Leads\Support\LeadInsights::ready() ? $lead->getAttribute('heat') : null;
    $heat = isset(\App\Modules\Leads\Support\LeadInsights::HEAT[$heat]) && (($all ?? false) || $heat !== 'cold') ? $heat : null;
@endphp
@if ($heat !== null)
    <span class="lq-heat lq-heat-{{ $heat }}" title="{{ filled($lead->getAttribute('heat_reason')) ? ucfirst($lead->getAttribute('heat_reason')).'. ' : '' }}Rated by AI from what they asked for.">
        @if ($heat === 'hot') <x-heroicon-m-fire /> @endif{{ \App\Modules\Leads\Support\LeadInsights::HEAT[$heat] }}
    </span>
@endif
