{{-- When a lead arrived (user request, 19 Oct 2026), in the viewer's own time zone (ViewerTime): for the first three days
     "6h ago" with the day and time under it, after that the date and time. `inline` puts it on one line. --}}
@props(['at' => null, 'inline' => false])
@php
    $local = $at !== null ? \App\Modules\Core\Support\ViewerTime::local($at) : null;
    $recent = $local !== null && $local->gt(now()->subDays(3));
    $clock = $local?->format('H:i');

    [$main, $sub] = match (true) {
        $local === null => ['—', null],
        $recent => [
            $local->diffForHumans(short: true),
            match (true) {
                $local->isToday() => 'Today, '.$clock,
                $local->isYesterday() => 'Yesterday, '.$clock,
                default => $local->format('D j M').', '.$clock,
            },
        ],
        default => [$local->format($local->isCurrentYear() ? 'j M' : 'j M Y'), $local->format('D').', '.$clock],
    };
@endphp
@if ($inline)
    <span {{ $attributes }} @if ($local) title="{{ $local->format('l j F Y, H:i (T)') }}" @endif>{{ $recent ? $main.' · '.$sub : ($local ? $main.', '.$clock : $main) }}</span>
@else
    <span {{ $attributes->class(['lq-received']) }} @if ($local) title="{{ $local->format('l j F Y, H:i (T)') }}" @endif>
        <span class="lq-received-main">{{ $main }}</span>
        @if ($sub)
            <span class="lq-received-sub">{{ $sub }}</span>
        @endif
    </span>
@endif
