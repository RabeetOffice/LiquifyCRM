{{-- When something is due, in words: "Today, 14:00", "Tomorrow", "Overdue · 3 Oct". Red once it is late. --}}
@props(['at' => null, 'done' => false, 'time' => true, 'empty' => '—'])
@php
    $late = $at !== null && ! $done && $at->isPast();
    $clock = $time ? ', '.$at?->format('H:i') : '';

    $label = match (true) {
        $at === null => null,
        $at->isToday() => 'Today'.$clock,
        $at->isTomorrow() => 'Tomorrow'.$clock,
        $at->isYesterday() => 'Yesterday'.$clock,
        default => $at->format($at->isCurrentYear() ? 'j M' : 'j M Y').($late ? '' : $clock),
    };
@endphp
@if ($at === null)
    <span {{ $attributes->class(['lq-due', 'lq-due-none']) }}>{{ $empty }}</span>
@else
    <span {{ $attributes->class(['lq-due', 'lq-due-late' => $late, 'lq-due-today' => ! $late && ! $done && $at->isToday(), 'lq-due-done' => $done]) }} title="{{ $at->format('j M Y, H:i') }}">
        @if ($late)
            <x-heroicon-m-exclamation-circle />
        @else
            <x-heroicon-m-calendar-days />
        @endif
        {{ $late && ! $at->isToday() ? 'Overdue · '.$label : $label }}
    </span>
@endif
