@php
    $status = app(\App\Modules\System\Support\SystemStatus::class);
    $last = $status->lastCronRun();
    $healthy = $status->cronIsHealthy();
@endphp

<a href="{{ url('/admin/system') }}" @class(['lq-status', 'lq-status-warn' => ! $healthy]) title="Scheduler status">
    <span class="lq-status-dot" aria-hidden="true"></span>
    <span class="lq-status-label">{{ $healthy ? 'Running' : 'Scheduler quiet' }}</span>
    <span class="lq-status-detail">{{ $last ? 'Last run '.$last->diffForHumans() : 'No run recorded' }}</span>
</a>
