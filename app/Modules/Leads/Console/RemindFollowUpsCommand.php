<?php

declare(strict_types=1);

namespace App\Modules\Leads\Console;

use App\Modules\Brands\Models\Brand;
use App\Modules\Core\Notifications\AppNotification;
use App\Modules\Core\Scopes\BrandScope;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Models\Task;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Every minute (user request, 20 Oct 2026): a follow-up coming up is
 * announced to its owner 10, 5 and 1 minutes before, in the bell, with the
 * chime, a desktop alert and a phone push where they are on. No email: three
 * emails for one call would be noise. Each reminder goes once per follow-up
 * and time (a follow-up moved to another time is reminded again), and a
 * minute the scheduler missed is caught up by the next run.
 */
final class RemindFollowUpsCommand extends Command
{
    /** Minutes before: each reminder covers the time down to the next one. */
    public const STEPS = [10, 5, 1];

    protected $signature = 'followups:remind';

    protected $description = 'Remind people of their follow-ups 10, 5 and 1 minutes before';

    public function handle(): int
    {
        $now = now();
        $sent = 0;
        $brands = [];

        foreach (self::STEPS as $index => $minutes) {
            $after = $now->addMinutes(self::STEPS[$index + 1] ?? 0);

            Task::query()
                ->acrossBrands()
                ->open()
                ->where('due_at', '>', $after)
                ->where('due_at', '<=', $now->addMinutes($minutes))
                ->with(['assignee', 'lead' => fn ($lead) => $lead->withoutGlobalScope(BrandScope::class)])
                ->orderBy('due_at')
                ->each(function (Task $task) use ($minutes, $now, &$sent, &$brands): void {
                    $person = $task->assignee ?? $task->lead?->assignee;

                    // Nobody to tell, somebody who cannot sign in, or a lead that is gone or put away.
                    if ($person === null || ! $person->isActive() || ($task->lead_id !== null && ($task->lead === null || $task->lead->isArchived()))) {
                        return;
                    }

                    if (! Cache::add('followup-reminder:'.$task->getKey().':'.$minutes.':'.$task->due_at->getTimestamp(), true, now()->addHours(3))) {
                        return;
                    }

                    $left = max(1, (int) ceil($now->diffInSeconds($task->due_at) / 60));
                    $brand = $brands[$task->brand_id] ??= Brand::query()->find($task->brand_id);
                    $lead = $task->lead;
                    // Set in the client's time ("5 PM UK time"): the reminder says it that way too.
                    $said = ($there = $task->theirTime($person)) !== null ? ' At '.$there.'.' : '';

                    $person->notify(new AppNotification(
                        'Follow-up in '.$left.' '.str('minute')->plural($left).': '.$task->title,
                        trim(($lead instanceof Lead ? $lead->name.($brand !== null ? ' · '.$brand->name : '').'.' : '').$said.' Have a look before it starts.'),
                        $left <= 1 ? 'warning' : 'info',
                        $lead instanceof Lead && $brand !== null ? $brand->url('leads/'.$lead->getKey()) : ($brand?->url('follow-ups')),
                        icon: 'heroicon-o-bell-alert',
                        actionLabel: $lead instanceof Lead ? 'Open lead' : 'Open follow-ups',
                    ));

                    $sent++;
                });
        }

        $this->info($sent.' follow-up '.str('reminder')->plural($sent).' sent.');

        return self::SUCCESS;
    }
}
