<?php

declare(strict_types=1);

namespace App\Modules\Core\Filament\App\Widgets;

use App\Modules\Auth\Models\User;
use App\Modules\Brands\Models\Brand;
use App\Modules\Core\Enums\BrandRole;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;

/**
 * Setup checklist from the "Easy for new users" section: each step ticks
 * itself when done. Steps that belong to later phases are shown muted.
 */
final class SetupChecklistWidget extends Widget
{
    protected static bool $isDiscovered = false;

    /** Rendered with the page: one request, no skeleton flash. */
    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'core::filament.widgets.setup-checklist';

    public static function canView(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->isGlobalAdmin();
    }

    /**
     * @return array{brand: Brand, steps: list<array{title: string, description: string, done: bool, url: ?string, soon: ?string}>, done: int, total: int}
     */
    public function getChecklist(): array
    {
        /** @var Brand $brand */
        $brand = Filament::getTenant();

        $departments = $brand->departments()->count();
        $managers = $brand->memberships()->where('role', BrandRole::Manager->value)->count();

        $steps = [
            [
                'title' => 'Create the brand',
                'description' => $brand->name.' is ready.',
                'done' => true,
                'url' => url('/admin/brands/'.$brand->getKey().'/edit'),
                'soon' => null,
            ],
            [
                'title' => 'Create departments',
                'description' => $departments > 0 ? $departments.' '.str('department')->plural($departments).' created.' : 'Teams such as Sales Team A or Publishing Consultants.',
                'done' => $departments > 0,
                'url' => url('/b/'.$brand->slug.'/departments'),
                'soon' => null,
            ],
            [
                'title' => 'Add Managers',
                'description' => $managers > 0 ? $managers.' '.str('Manager')->plural($managers).' added.' : 'Invite a Manager for each department; they add their own Agents.',
                'done' => $managers > 0,
                'url' => url('/b/'.$brand->slug.'/members'),
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

        return [
            'brand' => $brand,
            'steps' => $steps,
            'done' => count(array_filter($available, fn (array $step): bool => $step['done'])),
            'total' => count($available),
        ];
    }
}
