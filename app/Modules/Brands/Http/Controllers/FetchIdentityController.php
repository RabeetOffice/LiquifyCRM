<?php

declare(strict_types=1);

namespace App\Modules\Brands\Http\Controllers;

use App\Modules\Brands\Models\Brand;
use App\Modules\Brands\Support\BrandIdentity;
use App\Modules\Core\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/** POST /admin/brands/{brandId}/fetch-identity: fetch favicon and colours from the brand website. */
final class FetchIdentityController
{
    public function __invoke(int $brandId, BrandIdentity $identity): JsonResponse
    {
        $brand = Brand::query()->findOrFail($brandId);
        Gate::authorize('update', $brand);

        $result = $identity->fetch($brand);

        Audit::log('brands.identity_fetched', $brand, ['favicon' => $result['favicon'], 'colors' => $result['colors']], 'Favicon and colours fetched from the website', brand: $brand, logName: 'brands');

        return response()->json($result, $result['error'] === null ? 200 : 422);
    }
}
