{{--
    Where a website visitor is: flag and place, address (and whether it is a VPN or proxy), network and their own time.
    $who is a ChatVisitor or a ChatSession. Rows of a `lq-kv` list.
--}}
@php
    $place = \App\Modules\Chat\Support\VisitorLocation::label($who->city, $who->getAttribute('region'), $who->country);
    $zone = $who->getAttribute('timezone');
    // The computer's own time zone: on a browser itself, or on the visitor's latest browser on the website.
    $browserTz = ! \App\Modules\Chat\Support\LiveChat::tzReady() ? null : ($who instanceof \App\Modules\Chat\Models\ChatSession
        ? $who->browser_tz
        : \App\Modules\Chat\Models\ChatSession::query()->where('chat_visitor_id', $who->getKey())->whereNotNull('browser_tz')->latest('last_seen_at')->value('browser_tz'));
    $vpn = \App\Modules\Chat\Support\VisitorLocation::vpn($who->getAttribute('is_proxy'), $who->getAttribute('isp'), $who->country, $browserTz);@endphp
@if ($place !== '')
    <div>
        <dt><x-heroicon-o-map-pin /> Location</dt>
        <dd class="lq-where">@include('chat::partials.flag', ['code' => $who->country]) <span>{{ $place }}</span></dd>
    </div>
@endif
@if ($vpn['vpn'])
    <div class="lq-vpn-row">
        <dt><x-heroicon-o-shield-exclamation /> VPN detected</dt>
        <dd>@if ($vpn['real'])Their computer is set to @include('chat::partials.flag', ['code' => $vpn['real']]) {{ \App\Modules\Chat\Support\VisitorLocation::label(null, null, $vpn['real']) }} time @else The address belongs to a VPN, proxy or data centre @endif</dd>
    </div>
@endif
@if ($who->getAttribute('ip'))
    <div>
        <dt><x-heroicon-o-globe-alt /> IP address</dt>
        <dd class="lq-where">
            <span class="lq-tabular">{{ $who->getAttribute('ip') }}</span>
            @if ($vpn['vpn'])
                <x-ui.badge color="danger" title="The place of this address may not be where the person is.">VPN</x-ui.badge>
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
