<?php

declare(strict_types=1);

namespace App\Modules\Leads\Livewire\App;

use App\Modules\Auth\Models\User;
use App\Modules\Brands\Models\Brand;
use App\Modules\Core\Livewire\Concerns\WithToasts;
use App\Modules\Core\Support\ViewerTime;
use App\Modules\Leads\Actions\ScheduleFollowUp;
use App\Modules\Leads\Models\Task;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** Follow-ups: overdue, today, upcoming and done, for the leads a person may see. */
#[Layout('components.layouts.app')]
#[Title('Follow-ups')]
final class FollowUps extends Component
{
    use AuthorizesRequests;
    use WithPagination;
    use WithToasts;

    public Brand $brand;

    #[Url(except: 'today')]
    public string $tab = 'today';

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
            'tasks' => $tasks->with(['lead.status', 'assignee'])->paginate(25),
            'counts' => $counts,
        ]);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
