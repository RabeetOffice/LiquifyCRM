<?php

declare(strict_types=1);

namespace App\Modules\Departments\Models;

use App\Modules\Auth\Models\User;
use App\Modules\Core\Concerns\BelongsToBrand;
use App\Modules\Core\Enums\AssignmentMode;
use App\Modules\Core\Enums\DepartmentRole;
use App\Modules\Departments\Database\Factories\DepartmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A team inside a brand, led by one or more Managers with its Agents.
 *
 * @property int $id
 * @property int $brand_id
 * @property string $name
 * @property string|null $description
 * @property string|null $color
 * @property AssignmentMode $assignment_mode
 * @property int|null $last_assigned_user_id
 * @property int|null $created_by
 */
class Department extends Model
{
    use BelongsToBrand;

    /** @use HasFactory<DepartmentFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $fillable = ['brand_id', 'name', 'description', 'color', 'assignment_mode', 'last_assigned_user_id', 'created_by'];

    protected $attributes = ['assignment_mode' => 'manual'];

    protected function casts(): array
    {
        return ['assignment_mode' => AssignmentMode::class];
    }

    protected static function newFactory(): DepartmentFactory
    {
        return DepartmentFactory::new();
    }

    /** @return BelongsToMany<User, $this, DepartmentUser> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'department_user')
            ->using(DepartmentUser::class)
            ->withPivot(['id', 'role', 'created_by'])
            ->withTimestamps();
    }

    /** @return BelongsToMany<User, $this, DepartmentUser> */
    public function managers(): BelongsToMany
    {
        return $this->users()->wherePivot('role', DepartmentRole::Manager->value);
    }

    /** @return BelongsToMany<User, $this, DepartmentUser> */
    public function agents(): BelongsToMany
    {
        return $this->users()->wherePivot('role', DepartmentRole::Agent->value);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function hasManager(User|int $user): bool
    {
        $userId = $user instanceof User ? $user->getKey() : $user;

        return $this->managers()->where('users.id', $userId)->exists();
    }

    public function hasMember(User|int $user): bool
    {
        $userId = $user instanceof User ? $user->getKey() : $user;

        return $this->users()->where('users.id', $userId)->exists();
    }

    /**
     * Add or update a person in this department with a role.
     */
    public function addMember(User $user, DepartmentRole $role, ?User $by = null): void
    {
        $this->users()->syncWithoutDetaching([
            $user->getKey() => ['role' => $role->value, 'created_by' => $by?->getKey()],
        ]);
    }
}
