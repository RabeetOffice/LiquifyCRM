<?php

declare(strict_types=1);

namespace App\Modules\Core\Support;

use App\Modules\Brands\Models\Brand;
use App\Modules\Core\Enums\BrandRole;
use App\Modules\Leads\Models\Lead;
use App\Modules\Meta\Models\MetaSource;
use App\Modules\Sales\Models\Target;
use App\Modules\Sites\Models\Site;

/**
 * Setup checklist from the "Easy for new users" section: each step ticks
 * itself when done. Steps that belong to later phases are shown muted.
 *
 * @phpstan-type Step array{title: string, description: string, done: bool, url: ?string, soon: ?string}
 */
final class SetupChecklist
{
    /**
     * @return array{steps: list<Step>, done: int, total: int, percent: int}
     */
    public static function for(Brand $brand): array
    {
        $departments = $brand->departments()->count();
        $managers = $brand->memberships()->where('role', BrandRole::Manager->value)->count();
        $leads = Lead::query()->forBrand($brand)->count();
        $sites = Site::query()->forBrand($brand)->count();
        $targets = Target::query()->forBrand($brand)->where('period', now()->format('Y-m'))->count();
        $pages = MetaSource::query()->where('brand_id', $brand->getKey())->count();

        $steps = [
            [
                'title' => 'Create the brand',
                'description' => $brand->name.' is ready.',
                'done' => true,
                'url' => route('admin.brands.edit', ['brandId' => $brand->getKey()]),
                'soon' => null,
            ],
            [
                'title' => 'Create departments',
                'description' => $departments > 0 ? $departments.' '.str('department')->plural($departments).' created.' : 'Teams such as Sales Team A or Publishing Consultants.',
                'done' => $departments > 0,
                'url' => route('brand.departments.index', ['brand' => $brand->slug]),
                'soon' => null,
            ],
            [
                'title' => 'Add Managers',
                'description' => $managers > 0 ? $managers.' '.str('Manager')->plural($managers).' added.' : 'Invite a Manager for each department; they add their own Agents.',
                'done' => $managers > 0,
                'url' => route('brand.members.index', ['brand' => $brand->slug]),
                'soon' => null,
            ],
            [
                'title' => 'Add your first lead',
                'description' => $leads > 0 ? $leads.' '.str('lead')->plural($leads).' in this brand.' : 'Add one by hand, import a CSV, or connect a website.',
                'done' => $leads > 0,
                'url' => route('brand.leads.index', ['brand' => $brand->slug]),
                'soon' => null,
            ],
            [
                'title' => 'Connect your first website',
                'description' => $sites > 0 ? $sites.' '.str('website')->plural($sites).' connected.' : 'Download the pre-filled liquify.php and paste 2 lines into the form handler.',
                'done' => $sites > 0,
                'url' => $sites > 0 ? route('brand.sites.index', ['brand' => $brand->slug]) : route('brand.sites.connect', ['brand' => $brand->slug]),
                'soon' => null,
            ],
            [
                'title' => 'Connect Facebook',
                'description' => $pages > 0 ? $pages.' Facebook '.str('Page')->plural($pages).' mapped to this brand.' : 'One connection for all brands; map each Page to its brand.',
                'done' => $pages > 0,
                'url' => route('admin.integrations'),
                'soon' => null,
            ],
            [
                'title' => 'Set monthly targets',
                'description' => $targets > 0 ? $targets.' '.str('target')->plural($targets).' set for '.now()->format('F').'.' : 'Revenue or number of sales for the brand, each department and each person.',
                'done' => $targets > 0,
                'url' => route('brand.targets', ['brand' => $brand->slug]),
                'soon' => null,
            ],
        ];

        // Every step is live now; "soon" stays in the shape for steps of later phases.
        $available = $steps;
        $done = count(array_filter($available, fn (array $step): bool => $step['done']));
        $total = count($available);

        return [
            'steps' => $steps,
            'done' => $done,
            'total' => $total,
            'percent' => (int) round($done / max($total, 1) * 100),
        ];
    }
}
