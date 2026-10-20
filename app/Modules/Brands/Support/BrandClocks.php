<?php

declare(strict_types=1);

namespace App\Modules\Brands\Support;

use App\Modules\Auth\Models\User;
use App\Modules\Brands\Models\Brand;
use App\Modules\Core\Support\ViewerTime;

/**
 * The clocks a person may add to the top bar (user request, 20 Oct 2026):
 * only the time zones LiquifyCRM's brands work in, not four hundred. Each
 * names the brands of that zone the person works in (Admins: all of them).
 */
final class BrandClocks
{
    /** @return list<array{zone: string, city: string, brands: string}> */
    public static function choices(User $user): array
    {
        $mine = $user->accessibleBrands()->keyBy(fn (Brand $brand): int => (int) $brand->getKey());

        return Brand::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'timezone'])
            ->filter(fn (Brand $brand): bool => ViewerTime::valid($brand->timezone))
            ->groupBy('timezone')
            ->map(fn ($brands, string $zone): array => [
                'zone' => $zone,
                'city' => ViewerTime::city($zone),
                'brands' => $brands->filter(fn (Brand $brand): bool => $mine->has((int) $brand->getKey()))->pluck('name')->implode(', '),
            ])
            ->sortBy('city')
            ->values()
            ->all();
    }
}
