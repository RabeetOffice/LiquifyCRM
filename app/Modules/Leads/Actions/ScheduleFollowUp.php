<?php

declare(strict_types=1);

namespace App\Modules\Leads\Actions;

use App\Modules\Auth\Models\User;
use App\Modules\Core\Support\Audit;
use App\Modules\Core\Support\ViewerTime;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Models\Task;
use App\Modules\Leads\Support\FollowUpZones;
use Carbon\CarbonInterface;

/**
 * "Follow up on": a task on the lead, owned by its Agent (or whoever set it).
 * A time said in another time zone ("5 PM UK time") keeps that zone, so the
 * follow-up shows it beside the viewer's own time; the reminders come at
 * that moment wherever the owner is.
 */
final class ScheduleFollowUp
{
    /** @param  string|null  $timezone  the zone the time was said in, when not the setter's own */
    public function handle(Lead $lead, CarbonInterface $dueAt, string $title = 'Follow up', ?User $by = null, ?User $owner = null, ?string $timezone = null): Task
    {
        $timezone = FollowUpZones::ready() && ViewerTime::valid($timezone) ? $timezone : null;

        $task = $lead->tasks()->create([
            'brand_id' => $lead->brand_id,
            'user_id' => $owner?->getKey() ?? $lead->assigned_to ?? $by?->getKey(),
            'created_by' => $by?->getKey(),
            'title' => trim($title) ?: 'Follow up',
            'due_at' => $dueAt,
            ...($timezone !== null ? ['timezone' => $timezone] : []),
        ]);

        $this->syncNextFollowUp($lead);

        $said = $timezone !== null ? ' ('.$dueAt->setTimezone($timezone)->format('g:i A').' '.ViewerTime::city($timezone).' time)' : '';

        Audit::log('leads.follow_up_scheduled', $lead, ['task_id' => $task->getKey(), 'due_at' => $dueAt->toIso8601String(), 'timezone' => $timezone], 'Follow-up set for '.$dueAt->format('j M, H:i').$said, causer: $by, logName: 'leads');

        return $task;
    }

    /** Moved to another day or time (dragged on the calendar): its reminders follow the new time. */
    public function reschedule(Task $task, CarbonInterface $dueAt, ?User $by = null): Task
    {
        $before = $task->due_at;
        $task->forceFill(['due_at' => $dueAt])->save();

        if ($task->lead !== null) {
            $this->syncNextFollowUp($task->lead);
            Audit::log('leads.follow_up_moved', $task->lead, ['task_id' => $task->getKey(), 'from' => $before->toIso8601String(), 'to' => $dueAt->toIso8601String()], 'Follow-up moved: '.$task->title, causer: $by, logName: 'leads');
        }

        return $task;
    }

    public function complete(Task $task, ?User $by = null): Task
    {
        $task->forceFill(['done_at' => now()])->save();

        if ($task->lead !== null) {
            $this->syncNextFollowUp($task->lead);
            Audit::log('leads.follow_up_done', $task->lead, ['task_id' => $task->getKey()], 'Follow-up done: '.$task->title, causer: $by, logName: 'leads');
        }

        return $task;
    }

    private function syncNextFollowUp(Lead $lead): void
    {
        $next = $lead->tasks()->open()->orderBy('due_at')->value('due_at');

        $lead->forceFill(['next_follow_up_at' => $next, 'last_activity_at' => now()])->saveQuietly();
    }
}
