<?php

declare(strict_types=1);

namespace App\Modules\Sites\Database\Factories;

use App\Modules\Brands\Models\Brand;
use App\Modules\Sites\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Site> */
final class SiteFactory extends Factory
{
    protected $model = Site::class;

    public function definition(): array
    {
        $domain = fake()->unique()->domainName();

        return [
            'brand_id' => Brand::factory(),
            'name' => ucfirst((string) strtok($domain, '.')),
            'url' => 'https://'.$domain,
            'domain' => $domain,
        ];
    }
}
