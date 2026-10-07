<?php

declare(strict_types=1);

namespace App\Modules\Brands\Database\Factories;

use App\Modules\Brands\Models\Brand;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Brand>
 */
final class BrandFactory extends Factory
{
    protected $model = Brand::class;

    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'website' => 'https://'.Str::slug($name).'.example.com',
            'currency' => 'GBP',
            'timezone' => 'Europe/London',
            'colors' => ['primary' => fake()->hexColor()],
            'settings' => [],
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
