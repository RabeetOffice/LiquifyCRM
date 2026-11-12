{{-- Real person or probably a bot (VisitorKind). $kind: person, bot or null; $long for the sentence beside a chat. --}}
@if (($kind ?? null) === 'person')
    <x-ui.badge color="success" title="They scrolled, tapped, typed or wrote in the chat.">{{ ($long ?? false) ? 'Yes, active on the page' : 'Real person' }}</x-ui.badge>
@elseif (($kind ?? null) === 'bot')
    <x-ui.badge color="gray" title="A VPN or data-centre address, and nobody scrolled, tapped or typed on the page.">Probably a bot</x-ui.badge>
@endif
