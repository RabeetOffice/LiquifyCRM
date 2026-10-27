<?php

declare(strict_types=1);

namespace App\Modules\Leads\Support;

use App\Modules\Auth\Models\User;
use App\Modules\Brands\Models\Brand;
use App\Modules\Leads\Models\Lead;
use App\Modules\Sites\Support\LeadSites;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * What the leads email every three hours says (user request, 27 Oct 2026: one
 * email a lead filled the inbox). Per brand: the leads that came in since the
 * last one and how many still wait for a person. The same people as before get
 * it: Admins for every brand, a Manager for the teams they lead, and a brand's
 * notification addresses for that brand. Nothing new, no email.
 */
final class LeadDigest
{
    /** Leads listed per brand; the rest are counted. */
    public const SHOWN = 8;

    /**
     * @return list<array{brand: Brand, leads: Collection<int, Lead>, new: int, waiting: int, url: string}>
     */
    public static function forUser(User $user, CarbonInterface $from, CarbonInterface $to): array
    {
        if (! $user->isActive()) {
            return [];
        }

        $sections = [];

        foreach ($user->accessibleBrands() as $brand) {
            // Admins: the whole brand. Managers: the teams they lead (an Upsell team never gets leads).
            $teams = $user->isGlobalAdmin() ? null : ($user->isManagerIn($brand) ? $user->managedDepartmentIds($brand) : []);

            if ($teams === []) {
                continue;
            }

            $section = self::section($brand, $from, $to, $teams);

            if ($section !== null) {
                $sections[] = $section;
            }
        }

        return $sections;
    }

    /**
     * The brand's part alone, for its notification addresses.
     *
     * @return list<array{brand: Brand, leads: Collection<int, Lead>, new: int, waiting: int, url: string}>
     */
    public static function forBrand(Brand $brand, CarbonInterface $from, CarbonInterface $to): array
    {
        $section = self::section($brand, $from, $to, null);

        return $section !== null ? [$section] : [];
    }

    /**
     * @param  list<int>|null  $teams  null: every lead of the brand
     * @return array{brand: Brand, leads: Collection<int, Lead>, new: int, waiting: int, url: string}|null
     */
    private static function section(Brand $brand, CarbonInterface $from, CarbonInterface $to, ?array $teams): ?array
    {
        $scope = fn (): Builder => Lead::query()
            ->forBrand($brand)
            ->when($teams !== null, fn (Builder $query): Builder => $query->whereIn('department_id', $teams));

        $new = $scope()->where('created_at', '>', $from)->where('created_at', '<=', $to)->count();

        if ($new === 0) {
            return null;
        }

        $leads = $scope()
            ->where('created_at', '>', $from)
            ->where('created_at', '<=', $to)
            ->with(['department', 'assignee', 'status'])
            ->latest('created_at')
            ->latest('id')
            ->limit(self::SHOWN)
            ->get();

        LeadSites::attach($leads);

        return [
            'brand' => $brand,
            'leads' => $leads,
            'new' => $new,
            'waiting' => $scope()->open()->unassigned()->count(),
            'url' => $brand->url('leads?tab=unassigned'),
        ];
    }
}
