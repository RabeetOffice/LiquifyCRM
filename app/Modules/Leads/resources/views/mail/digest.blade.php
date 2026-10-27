<x-mail::message>
# {{ $headline }}

{{ $intro }}

{!! $body !!}

<x-mail::subcopy>
You get this every three hours when new leads came in, instead of one email a lead. The bell, the sound and the desktop alerts in {{ config('app.name') }} still tell you about each lead the moment it arrives.
</x-mail::subcopy>
</x-mail::message>
