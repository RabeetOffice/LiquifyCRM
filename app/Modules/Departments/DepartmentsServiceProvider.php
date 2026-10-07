<?php

declare(strict_types=1);

namespace App\Modules\Departments;

use App\Modules\Core\ModuleServiceProvider;
use App\Modules\Departments\Models\Department;
use App\Modules\Departments\Policies\DepartmentPolicy;

final class DepartmentsServiceProvider extends ModuleServiceProvider
{
    protected string $module = 'Departments';

    protected array $policies = [
        Department::class => DepartmentPolicy::class,
    ];
}
