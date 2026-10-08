<?php

declare(strict_types=1);

namespace App\Modules\Core\Support;

use App\Modules\Brands\Models\Brand;
use App\Modules\Core\Enums\BrandRole;
use App\Modules\Leads\Models\Lead;

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
                'description' => $leads > 0 ? $leads.' '.str('lead')->plural($leads).' in this brand.' : 'Add one by hand or import a CSV while the website connection is being built.',
                'done' => $leads > 0,
                'url' => route('brand.leads.index', ['brand' => $brand->slug]),
                'soon' => null,
            ],
            [
                'title' => 'Connect your first website',
                'description' => 'Download the pre-filled liquify.php and paste 2 lines into the form handler.',
                'done' => false,
                'url' => null,
                'soon' => 'Week 3',
            ],
            [
                'title' => 'Connect Facebook',
                'description' => 'One connection for all brands; map each Page to its brand.',
                'done' => false,
                'url' => null,
                'soon' => 'Week 4',
            ],
            [
                'title' => 'Set monthly targets',
                'description' => 'Revenue or number of sales for the brand, each department and each person.',
                'done' => false,
                'url' => null,
                'soon' => 'Week 5',
            ],
        ];

        $available = array_filter($steps, fn (array $step): bool => $step['soon'] === null);
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
