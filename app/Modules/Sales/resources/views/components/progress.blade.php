@props(['progress', 'compact' => false])
{{-- Achieved / target. Above 100% the bar becomes the highlighted Over-achieved badge. --}}
<div {{ $attributes->class(['lq-target', 'lq-target-compact' => $compact]) }}>
    @if (! $progress->hasTarget())
        <span class="lq-muted text-sm">{{ $compact ? 'No target' : 'No target set for this month' }}</span>
    @elseif ($progress->isOver())
        <span class="lq-over" title="{{ $progress->label() }}">
            <x-heroicon-m-trophy />
            {{ $progress->overLabel() }}
        </span>
    @else
        <div class="lq-target-row">
            <span class="lq-target-label">{{ $progress->label() }}</span>
            <span class="lq-target-percent lq-tabular">{{ $progress->percent() }}%</span>
        </div>
        <div class="lq-progress" role="progressbar" aria-valuenow="{{ $progress->barWidth() }}" aria-valuemin="0" aria-valuemax="100">
            <div class="lq-progress-bar" style="width: {{ $progress->barWidth() }}%"></div>
        </div>
    @endif
</div>
