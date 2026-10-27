<?php

declare(strict_types=1);

namespace App\Modules\Leads\Support;

use App\Modules\Auth\Models\User;
use App\Modules\Brands\Models\Brand;
use App\Modules\Leads\Enums\LeadSource;
use App\Modules\Leads\Models\Lead;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The website leads dashboard (user request, 27 Oct 2026): one screen with the
 * leads that came from websites, across brands, read only. A "Website leads"
 * person sees the brands an Admin chose for them (`website_lead_brands`);
 * Admins see every brand. Facebook, Messenger, Instagram and hand-made leads
 * never show here. The table arrives with "Update system".
 */
final class WebsiteLeads
{
    private static bool $ready = false;

    public static function ready(): bool
    {
        return self::$ready = self::$ready || Schema::hasTable('website_lead_brands');
    }

    public static function canOpen(User $user): bool
    {
        return $user->isActive() && ($user->isGlobalAdmin() || ($user->isWebsiteLeads() && self::ready()));
    }

    /**
     * Ids of the brands chosen for a Website leads person.
     *
     * @return list<int>
     */
    public static function chosenBrandIds(User $user): array
    {
        if (! self::ready()) {
            return [];
        }

        return DB::table('website_lead_brands')->where('user_id', $user->getKey())->pluck('brand_id')->map(fn ($id): int => (int) $id)->all();
    }

    /**
     * The brands whose website leads this person reads.
     *
     * @return Collection<int, Brand>
     */
    public static function brandsFor(User $user): Collection
    {
        $query = Brand::query()->where('is_active', true)->orderBy('name');

        if (! $user->isGlobalAdmin()) {
            $query->whereKey($user->isWebsiteLeads() ? self::chosenBrandIds($user) : []);
        }

        return $query->get();
    }

    /**
     * Every website lead this person may read, in every brand chosen for them.
     *
     * @param  list<int>|null  $brandIds  the brands already looked up (brandsFor)
     * @return Builder<Lead>
     */
    public static function query(User $user, ?array $brandIds = null): Builder
    {
        $brandIds ??= self::brandsFor($user)->modelKeys();

        return Lead::query()->acrossBrands()
            ->where('source_type', LeadSource::Website->value)
            ->whereIn('brand_id', $brandIds === [] ? [0] : $brandIds);
    }

    /**
     * Choose the brands of a Website leads person (replaces the old choice).
     *
     * @param  list<int>  $brandIds
     */
    public static function choose(User $user, array $brandIds): void
    {
        $brandIds = array_values(array_unique(array_map('intval', $brandIds)));

        DB::transaction(function () use ($user, $brandIds): void {
            DB::table('website_lead_brands')->where('user_id', $user->getKey())->whereNotIn('brand_id', $brandIds === [] ? [0] : $brandIds)->delete();

            $have = self::chosenBrandIds($user);

            foreach (array_diff($brandIds, $have) as $brandId) {
                DB::table('website_lead_brands')->insert(['user_id' => $user->getKey(), 'brand_id' => $brandId, 'created_at' => now(), 'updated_at' => now()]);
            }
        });
    }
}
