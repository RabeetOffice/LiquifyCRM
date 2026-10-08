<?php

declare(strict_types=1);

namespace App\Modules\Leads\Policies;

use App\Modules\Auth\Models\User;
use App\Modules\Core\Enums\Permission;
use App\Modules\Leads\Models\Task;

/** Follow-ups follow their lead: whoever may update the lead may manage its tasks. */
final class TaskPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isGlobalAdmin() || $user->can(Permission::LeadsView->value);
    }

    public function view(User $user, Task $task): bool
    {
        return $user->getKey() === $task->user_id || ($task->lead !== null && $user->can('view', $task->lead));
    }

    public function update(User $user, Task $task): bool
    {
        return $user->getKey() === $task->user_id || ($task->lead !== null && $user->can('update', $task->lead));
    }

    public function delete(User $user, Task $task): bool
    {
        return $user->isGlobalAdmin() || $user->getKey() === $task->created_by;
    }
}
