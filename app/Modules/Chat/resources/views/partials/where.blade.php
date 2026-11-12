{{--
    Where a website visitor is: flag and place, address (and whether it is a VPN or proxy), network and their own time.
    $who is a ChatVisitor or a ChatSession. Rows of a `lq-kv` list.
--}}
@php
    $place = \App\Modules\Chat\Support\VisitorLocation::label($who->city, $who->getAttribute('region'), $who->country);
    $zone = $who->getAttribute('timezone');
@endphp
@if ($place !== '')
    <div>
        <dt><x-heroicon-o-map-pin /> Location</dt>
        <dd class="lq-where">@include('chat::partials.flag', ['code' => $who->country]) <span>{{ $place }}</span></dd>
    </div>
@endif
@if ($who->getAttribute('ip'))
    <div>
        <dt><x-heroicon-o-globe-alt /> IP address</dt>
        <dd class="lq-where">
            <span class="lq-tabular">{{ $who->getAttribute('ip') }}</span>
            @if ($who->getAttribute('is_proxy'))
                <x-ui.badge color="warning" title="The address belongs to a VPN, proxy or hosting company: the place may not be where the person is.">VPN or proxy</x-ui.badge>
            @endif
        </dd>
    </div>
@endif
@if ($who->getAttribute('isp'))
    <div>
        <dt><x-heroicon-o-signal /> Network</dt>
        <dd class="truncate" title="{{ $who->getAttribute('isp') }}">{{ $who->getAttribute('isp') }}</dd>
    </div>
@endif
@if ($zone)
    <div>
        <dt><x-heroicon-o-clock /> Their time</dt>
        <dd title="{{ $zone }}">{{ now($zone)->format('H:i') }} <span class="lq-muted">· {{ now($zone)->format('D') }}</span></dd>
    </div>
@endif
