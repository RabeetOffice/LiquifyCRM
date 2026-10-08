<?php

declare(strict_types=1);

namespace App\Modules\Brands\Console;

use App\Modules\Brands\Jobs\FetchBrandIdentity;
use App\Modules\Brands\Models\Brand;
use Illuminate\Console\Command;

/** Weekly: refresh each brand's favicon in case the website changed it. Colours the Admin chose are left alone. */
final class RefreshIdentityCommand extends Command
{
    protected $signature = 'brands:refresh-identity';

    protected $description = 'Refresh the favicon of every brand that had it fetched from its website';

    public function handle(): int
    {
        $ids = Brand::query()->where('is_active', true)->whereNotNull('website')->whereNotNull('identity_fetched_at')->pluck('id');

        foreach ($ids as $id) {
            FetchBrandIdentity::dispatch((int) $id, false);
        }

        $this->info($ids->count().' brand(s) queued.');

        return self::SUCCESS;
    }
}
