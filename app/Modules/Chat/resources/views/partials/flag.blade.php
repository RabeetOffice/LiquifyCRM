{{-- A country's flag as a small picture (Windows shows flag emoji as two letters), its code when the picture cannot load. --}}
@php($code = strtolower((string) ($code ?? '')))
@if (preg_match('/^[a-z]{2}$/', $code) === 1)
    <img class="lq-flag" src="https://flagcdn.com/w40/{{ $code }}.png" alt="{{ strtoupper($code) }}" title="{{ \App\Modules\Chat\Support\VisitorLocation::label(null, null, strtoupper($code)) }}" width="20" height="15" decoding="async" referrerpolicy="no-referrer" onerror="this.replaceWith(document.createTextNode(this.alt))">
@endif
