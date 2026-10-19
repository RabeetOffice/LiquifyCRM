<?php

declare(strict_types=1);

namespace App\Modules\Core\Livewire;

use App\Modules\Auth\Models\User;
use App\Modules\Brands\Models\Brand;
use App\Modules\Core\Enums\Permission;
use App\Modules\Core\Navigation\Menu;
use App\Modules\Core\Support\CurrentBrand;
use App\Modules\Departments\Models\Department;
use App\Modules\Leads\Models\Lead;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Component;

/**
 * Ctrl K / ⌘K search over leads, brands and departments, limited to what
 * the person may see and open: leads of one brand (the open one, or outside
 * a brand the one of their menu), only the departments they may edit, and
 * contact details matched by any part only for those who see them in full.
 */
final class GlobalSearch extends Component
{
    public bool $open = false;

    public string $query = '';

    /** @return array<string, list<array{label: string, detail: ?string, url: string, icon: string}>> */
    public function results(): array
    {
        $query = trim($this->query);

        if (mb_strlen($query) < 2) {
            return [];
        }

        /** @var User $user */
        $user = auth()->user();
        $current = app(CurrentBrand::class);
        $brand = $current->get() ?? Menu::homeBrand($user);
        $results = $brand !== null ? $current->within($brand, fn (): array => $this->inBrand($user, $brand, $query)) : [];

        $brands = $user->accessibleBrands()->filter(fn (Brand $item): bool => mb_stripos($item->name, $query) !== false)->take(4);

        if ($brands->isNotEmpty()) {
            $results['Brands'] = $brands->map(fn (Brand $item): array => [
                'label' => $item->name,
                'detail' => 'Open workspace',
                'url' => $item->url(),
                'icon' => 'heroicon-o-building-storefront',
            ])->values()->all();
        }

        return $results;
    }

    public function render(): View
    {
        return view('core::livewire.global-search', ['results' => $this->open ? $this->results() : []]);
    }

    /** @return array<string, list<array{label: string, detail: ?string, url: string, icon: string}>> */
    private function inBrand(User $user, Brand $brand, string $query): array
    {
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $query).'%';
        $fullContacts = $user->isGlobalAdmin() || $user->can(Permission::LeadsRevealContact->value);
        $results = [];

        $leads = Lead::query()
            ->visibleTo($user)
            ->where(fn (Builder $q): Builder => $fullContacts
                ? $q->where('name', 'like', $like)->orWhere('email', 'like', $like)->orWhere('phone', 'like', $like)
                // Masked contacts match only as a whole, so typed fragments cannot uncover them.
                : $q->where('name', 'like', $like)->orWhere('email', mb_strtolower($query))->orWhere('phone', $query))
            ->with(['status', 'assignee'])
            ->limit(6)
            ->get();

        if ($leads->isNotEmpty()) {
            $results['Leads'] = $leads->map(fn (Lead $lead): array => [
                'label' => $lead->name,
                'detail' => $lead->status->name.($lead->assignee ? ' · '.$lead->assignee->name : '').($lead->isArchived() ? ' · Archived' : ''),
                'url' => $brand->url('leads/'.$lead->getKey()),
                'icon' => 'heroicon-o-user',
            ])->all();
        }

        if ($user->isGlobalAdmin() || $user->isManagerIn($brand)) {
            // A Manager opens only the departments they lead.
            $departments = Department::query()
                ->where('name', 'like', $like)
                ->when(! $user->isGlobalAdmin(), fn (Builder $q): Builder => $q->whereKey($user->managedDepartmentIds($brand)))
                ->limit(4)
                ->get();

            if ($departments->isNotEmpty()) {
                $results['Departments'] = $departments->map(fn (Department $department): array => [
                    'label' => $department->name,
                    'detail' => null,
                    'url' => $brand->url('departments/'.$department->getKey().'/edit'),
                    'icon' => 'heroicon-o-rectangle-group',
                ])->all();
            }
        }

        return $results;
    }
}
