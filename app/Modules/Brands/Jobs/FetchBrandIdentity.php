<?php

declare(strict_types=1);

namespace App\Modules\Brands\Jobs;

use App\Modules\Brands\Models\Brand;
use App\Modules\Brands\Support\BrandIdentity;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Fetch a brand's favicon (and, the first time, its logo and colours) from
 * its website. Queued when a brand is added, and weekly for the favicon.
 */
final class FetchBrandIdentity implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public readonly int $brandId, public readonly bool $withColors = true, public readonly bool $withLogo = true) {}

    public function handle(BrandIdentity $identity): void
    {
        $brand = Brand::query()->find($this->brandId);

        if ($brand !== null && filled($brand->website)) {
            $identity->fetch($brand, $this->withColors, $this->withLogo, fresh: true);
        }
    }
}
