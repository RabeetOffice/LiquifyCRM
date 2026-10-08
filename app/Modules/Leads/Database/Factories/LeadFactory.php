<?php

declare(strict_types=1);

namespace App\Modules\Leads\Database\Factories;

use App\Modules\Brands\Models\Brand;
use App\Modules\Leads\Enums\LeadSource;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Models\LeadStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Lead> */
final class LeadFactory extends Factory
{
    protected $model = Lead::class;

    public function definition(): array
    {
        return [
            'brand_id' => Brand::factory(),
            'source_type' => LeadSource::Manual,
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => '+4477009'.fake()->unique()->numerify('#####'),
            'message' => fake()->sentence(12),
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (Lead $lead): void {
            if ($lead->getAttribute('status_id') === null) {
                LeadStatus::createDefaultsFor((int) $lead->brand_id);
                $lead->status_id = LeadStatus::defaultFor((int) $lead->brand_id)?->getKey();
            }
        });
    }
}
