<?php

declare(strict_types=1);

namespace App\Modules\Departments\Database\Factories;

use App\Modules\Brands\Models\Brand;
use App\Modules\Departments\Models\Department;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Department>
 */
final class DepartmentFactory extends Factory
{
    protected $model = Department::class;

    public function definition(): array
    {
        return [
            'brand_id' => Brand::factory(),
            'name' => fake()->unique()->randomElement([
                'Sales Team A', 'Sales Team B', 'Publishing Consultants', 'Marketing Team',
                'Editorial Desk', 'Author Success', 'Enterprise Sales', 'Retention Team',
            ]).' '.fake()->unique()->numberBetween(1, 9999),
            'description' => fake()->sentence(),
            'color' => fake()->hexColor(),
        ];
    }
}
