<?php

declare(strict_types=1);

namespace App\Modules\Leads\Livewire\App;

use App\Modules\Auth\Models\User;
use App\Modules\Brands\Models\Brand;
use App\Modules\Core\Livewire\Concerns\WithToasts;
use App\Modules\Core\Support\ViewerTime;
use App\Modules\Leads\Actions\ScheduleFollowUp;
use App\Modules\Leads\Livewire\Concerns\PreviewsLead;
use App\Modules\Leads\Models\Task;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Follow-ups: a calendar like a diary (week or month, user request, 20 Oct
 * 2026) in the person's own time zone, and the lists (overdue, today,
 * upcoming, done), for the leads a person may see. A click on one opens its
 * lead in the slide-over; dragging one to another day or time moves it.
 */
#[Layout('components.layouts.app')]
#[Title('Follow-ups')]
final class FollowUps extends Component
{
    use AuthorizesRequests;
    use PreviewsLead;
    use WithPagination;
    use WithToasts;

    /** The hours a week shows when nothing is planned outside them. */
    public const DAY_STARTS = 8;

    public const DAY_ENDS = 19;

    public Brand $brand;

    #[Url(except: 'today')]
    public string $tab = 'today';

    /** week or month (the calendar), or list. */
    #[Url(except: 'week')]
    public string $view = 'week';

    /** A day (Y-m-d) in the week or month shown, in the person's own time zone; empty for this week or month. */
    #[Url(except: '')]
    public string $date = '';

    public function mount(Brand $brand): void
    {
        $this->brand = $brand;
        $this->authorize('viewAny', Task::class);
    }

    public function paginationView(): string
    {
        return 'pagination.lq';
    }

    public function updatedTab(): void
    {
        $this->resetPage();
    }

    public function updatedView(): void
    {
        $this->view = in_array($this->view, ['week', 'month', 'list'], true) ? $this->view : 'week';
        $this->resetPage();
    }

    /** The next or the previous week or month. */
    public function go(int $step): void
    {
        $anchor = $this->anchor();
        $step = max(-1, min(1, $step));

        $this->date = ($this->view === 'month' ? $anchor->startOfMonth()->addMonths($step) : $anchor->addWeeks($step))->toDateString();
    }

    public function today(): void
    {
        $this->date = '';
    }

    /** From the calendar to one of the lists (the overdue ones, from the count above the calendar). */
    public function showList(string $tab): void
    {
        $this->tab = in_array($tab, ['overdue', 'today', 'upcoming', 'done'], true) ? $tab : 'today';
        $this->view = 'list';
        $this->resetPage();
    }

    /** From a day of the month: the week it is in. */
    public function showWeek(string $day): void
    {
        $this->date = $this->validDay($day) ?? '';
        $this->view = 'week';
    }

    public function complete(int $taskId): void
    {
        $task = Task::query()->visibleTo($this->user())->findOrFail($taskId);
        $this->authorize('update', $task);

        app(ScheduleFollowUp::class)->complete($task, $this->user());

        $this->toastSuccess('Follow-up done');
    }

    public function reopen(int $taskId): void
    {
        $task = Task::query()->visibleTo($this->user())->findOrFail($taskId);
        $this->authorize('update', $task);

        $task->forceFill(['done_at' => null])->save();

        if ($task->lead !== null) {
            $task->lead->forceFill(['next_follow_up_at' => $task->lead->tasks()->open()->orderBy('due_at')->value('due_at')])->saveQuietly();
        }

        $this->toastSuccess('Follow-up reopened');
    }

    /**
     * Dropped on another day (the month keeps its time) or at another time of
     * the week, in the person's own time zone.
     */
    public function move(int $taskId, string $day, ?int $minutes = null): void
    {
        $task = Task::query()->visibleTo($this->user())->findOrFail($taskId);
        $this->authorize('update', $task);

        $day = $this->validDay($day);

        if ($day === null || $task->isDone()) {
            return;
        }

        $local = ViewerTime::local($task->due_at);
        $minutes = $minutes === null ? $local->hour * 60 + $local->minute : max(0, min(24 * 60 - 15, intdiv($minutes, 15) * 15));
        $dueAt = CarbonImmutable::parse($day, ViewerTime::zone())->addMinutes($minutes)->setTimezone((string) config('app.timezone'));

        if ($dueAt->equalTo($task->due_at)) {
            return;
        }

        app(ScheduleFollowUp::class)->reschedule($task, $dueAt, $this->user());

        $this->toastSuccess('Follow-up moved', $task->title.': '.ViewerTime::local($dueAt)->format('D j M, H:i').'.');
    }

    #[On('lead-updated')]
    public function leadUpdated(): void
    {
        // The calendar redraws with the follow-ups as they are now.
    }

    #[On('lead-deleted')]
    public function leadDeleted(): void
    {
        $this->open = null;
    }

    public function render(): View
    {
        $base = fn (): Builder => Task::query()->visibleTo($this->user());

        $counts = [
            'overdue' => $base()->overdue()->count(),
            'today' => $base()->dueToday()->count(),
            'upcoming' => $base()->open()->where('due_at', '>', ViewerTime::endOfToday())->count(),
        ];

        $tasks = match ($this->tab) {
            'overdue' => $base()->overdue()->orderBy('due_at'),
            'upcoming' => $base()->open()->where('due_at', '>', ViewerTime::endOfToday())->orderBy('due_at'),
            'done' => $base()->whereNotNull('done_at')->orderByDesc('done_at'),
            default => $base()->dueToday()->orderBy('due_at'),
        };

        return view('leads::livewire.app.follow-ups', [
            'tasks' => $this->view === 'list' ? $tasks->with(['lead.status', 'assignee'])->paginate(25) : null,
            'counts' => $counts,
            'calendar' => $this->view === 'list' ? null : $this->calendar($base),
            'zoneCity' => ViewerTime::city(ViewerTime::zone()),
            'previewLead' => $this->previewLead($this->user()),
        ]);
    }

    /**
     * The days shown and the follow-ups on each, in the person's own time zone.
     *
     * @param  callable(): Builder<Task>  $base
     * @return array{title: string, days: list<array{date: string, label: string, day: int, today: bool, inMonth: bool, past: bool, events: list<array<string, mixed>>}>, hours: list<int>, from: int, to: int, now: int|null}
     */
    private function calendar(callable $base): array
    {
        $zone = ViewerTime::zone();
        $anchor = $this->anchor();
        $today = ViewerTime::now()->toDateString();
        $stored = (string) config('app.timezone');

        [$start, $end] = $this->view === 'month'
            ? [$anchor->startOfMonth()->startOfWeek(CarbonInterface::MONDAY), $anchor->endOfMonth()->endOfWeek(CarbonInterface::SUNDAY)->addDay()->startOfDay()]
            : [$anchor->startOfWeek(CarbonInterface::MONDAY), $anchor->startOfWeek(CarbonInterface::MONDAY)->addWeek()];

        $tasks = $base()
            ->where('due_at', '>=', $start->setTimezone($stored))
            ->where('due_at', '<', $end->setTimezone($stored))
            ->with(['lead.status', 'assignee'])
            ->orderBy('due_at')
            ->limit(600)
            ->get();

        $byDay = [];
        $from = self::DAY_STARTS * 60;
        $to = self::DAY_ENDS * 60;

        foreach ($tasks as $task) {
            $local = $task->due_at->setTimezone($zone);
            $minutes = $local->hour * 60 + $local->minute;
            $from = min($from, intdiv($minutes, 60) * 60);
            // Room for the whole block (45 minutes tall) under a late one.
            $to = max($to, min(24 * 60, (int) ceil(($minutes + 45) / 60) * 60));

            $byDay[$local->toDateString()][] = [
                'id' => (int) $task->getKey(),
                'title' => $task->title,
                'time' => $local->format('H:i'),
                // Set in the client's time ("5 PM UK time"): said that way in the block's tooltip.
                'theirs' => $task->theirTime(),
                'minutes' => $minutes,
                'lead' => $task->lead,
                'owner' => $task->assignee?->name,
                'done' => $task->isDone(),
                'late' => ! $task->isDone() && $task->due_at->isPast(),
                'canOpen' => $task->lead !== null && $this->user()->can('view', $task->lead),
                'canMove' => ! $task->isDone() && $this->user()->can('update', $task),
            ];
        }

        $days = [];

        for ($day = $start; $day->lt($end); $day = $day->addDay()) {
            $events = $byDay[$day->toDateString()] ?? [];

            $days[] = [
                'date' => $day->toDateString(),
                'label' => $day->format('D'),
                'day' => $day->day,
                'today' => $day->toDateString() === $today,
                'inMonth' => $this->view !== 'month' || $day->isSameMonth($anchor),
                'past' => $day->toDateString() < $today,
                'events' => $this->view === 'week' ? $this->sideBySide($events) : $events,
            ];
        }

        $now = ViewerTime::now();
        $nowMinutes = $now->hour * 60 + $now->minute;
        $last = $end->subDay();

        return [
            'title' => match (true) {
                $this->view === 'month' => $anchor->format('F Y'),
                $start->isSameMonth($last) => $start->format('j').' – '.$last->format('j F Y'),
                default => $start->format('j M').' – '.$last->format('j M Y'),
            },
            'days' => $days,
            'hours' => range(intdiv($from, 60), intdiv($to, 60) - 1),
            'from' => $from,
            'to' => $to,
            'now' => $nowMinutes >= $from && $nowMinutes <= $to ? $nowMinutes : null,
        ];
    }

    /**
     * Follow-ups of one day that would cover each other (closer than 45
     * minutes, the height of one) stand side by side, like a diary.
     *
     * @param  list<array<string, mixed>>  $events
     * @return list<array<string, mixed>>
     */
    private function sideBySide(array $events): array
    {
        $clusters = [];

        foreach ($events as $event) {
            $last = array_key_last($clusters);

            if ($last !== null && $event['minutes'] < $clusters[$last]['until']) {
                $clusters[$last]['events'][] = $event;
                $clusters[$last]['until'] = max($clusters[$last]['until'], $event['minutes'] + 45);
            } else {
                $clusters[] = ['until' => $event['minutes'] + 45, 'events' => [$event]];
            }
        }

        $placed = [];

        foreach ($clusters as $cluster) {
            $count = count($cluster['events']);

            foreach ($cluster['events'] as $index => $event) {
                $placed[] = [...$event, 'column' => $index, 'columns' => $count];
            }
        }

        return $placed;
    }

    /** The day the calendar is around, in the person's own time zone. */
    private function anchor(): CarbonImmutable
    {
        $day = $this->validDay($this->date);

        return $day !== null ? CarbonImmutable::parse($day, ViewerTime::zone()) : ViewerTime::now()->startOfDay();
    }

    private function validDay(string $day): ?string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) !== 1) {
            return null;
        }

        [$year, $month, $date] = array_map('intval', explode('-', $day));

        return checkdate($month, $date, $year) ? $day : null;
    }

    private function user(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
