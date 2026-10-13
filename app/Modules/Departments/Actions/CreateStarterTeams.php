<?php

declare(strict_types=1);

namespace App\Modules\Departments\Actions;

use App\Modules\Auth\Models\User;
use App\Modules\Brands\Models\Brand;
use App\Modules\Core\Support\Audit;
use App\Modules\Departments\Models\Department;
use App\Modules\Departments\Support\StarterTeams;
use App\Modules\Sales\Support\ClientCare;

/**
 * Creates the chosen starter departments of a brand in one go. A name the
 * brand already uses is left as it is, and one that was removed comes back.
 */
final class CreateStarterTeams
{
    /**
     * @param  list<string>  $keys  keys of StarterTeams::all()
     * @return list<Department> the departments that were created or brought back
     */
    public function handle(Brand $brand, array $keys, ?User $by = null): array
    {
        $made = [];

        foreach (StarterTeams::all() as $key => $team) {
            if (! in_array($key, $keys, true)) {
                continue;
            }

            $existing = Department::withTrashed()->forBrand($brand)->where('name', $team['name'])->first();

            if ($existing !== null && ! $existing->trashed()) {
                continue;
            }

            if ($existing !== null) {
                $existing->restore();
                $department = $existing;
            } else {
                $department = Department::query()->create([
                    'brand_id' => $brand->getKey(),
                    'name' => $team['name'],
                    'color' => $team['color'],
                    'description' => $team['description'],
                    'created_by' => $by?->getKey(),
                    // The column arrives with "Update system"; until then every department is front sales.
                    ...(ClientCare::ready() ? ['kind' => $team['kind']->value] : []),
                ]);
            }

            Audit::log('departments.created', $department, ['name' => $department->name, 'starter' => true], 'Department created', brand: $brand, logName: 'departments');

            $made[] = $department;
        }

        return $made;
    }
}
