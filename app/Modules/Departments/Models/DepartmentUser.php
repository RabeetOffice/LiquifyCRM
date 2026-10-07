<?php

declare(strict_types=1);

namespace App\Modules\Departments\Models;

use App\Modules\Auth\Models\User;
use App\Modules\Core\Enums\DepartmentRole;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Who leads and who works in each department.
 *
 * @property int $id
 * @property int $department_id
 * @property int $user_id
 * @property DepartmentRole $role
 * @property int|null $created_by
 */
class DepartmentUser extends Pivot
{
    public $incrementing = true;

    protected $table = 'department_user';

    protected $fillable = ['department_id', 'user_id', 'role', 'created_by'];

    protected function casts(): array
    {
        return [
            'role' => DepartmentRole::class,
        ];
    }

    /** @return BelongsTo<Department, $this> */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
