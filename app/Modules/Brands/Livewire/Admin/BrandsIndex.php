<?php

declare(strict_types=1);

namespace App\Modules\Brands\Livewire\Admin;

use App\Modules\Brands\Models\Brand;
use App\Modules\Core\Livewire\Concerns\WithConfirmation;
use App\Modules\Core\Livewire\Concerns\WithTableState;
use App\Modules\Core\Livewire\Concerns\WithToasts;
use App\Modules\Core\Support\Audit;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Brands: the workspaces. Admins add, edit and switch them off. */
#[Layout('components.layouts.app')]
#[Title('Brands')]
final class BrandsIndex extends Component
{
    use AuthorizesRequests;
    use WithConfirmation;
    use WithTableState;
    use WithToasts;

    #[Url(except: '')]
    public string $active = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Brand::class);
    }

    public function updatedActive(): void
    {
        $this->resetPage();
    }

    public function confirmDelete(int $brandId): void
    {
        $brand = Brand::query()->findOrFail($brandId);
        $this->authorize('delete', $brand);

        $this->askConfirmation('delete', [$brandId], 'Remove '.$brand->name.'?', 'The brand is hidden from everyone and stops receiving leads. Its data is kept.', 'Remove brand', 'danger');
    }

    public function delete(int $brandId): void
    {
        $brand = Brand::query()->findOrFail($brandId);
        $this->authorize('delete', $brand);

        $brand->delete();

        Audit::log('brands.deleted', $brand, ['name' => $brand->name], 'Brand removed', brand: $brand, logName: 'brands');

        $this->toastSuccess($brand->name.' removed');
    }

    public function render(): View
    {
        $like = '%'.$this->search.'%';

        $brands = Brand::query()
            ->withCount(['departments', 'memberships'])
            ->when($this->search !== '', fn (Builder $query): Builder => $query->where(fn (Builder $q): Builder => $q->where('name', 'like', $like)->orWhere('slug', 'like', $like)->orWhere('website', 'like', $like)))
            ->when($this->active !== '', fn (Builder $query): Builder => $query->where('is_active', $this->active === '1'))
            ->orderBy($this->sortColumn(['name', 'created_at', 'departments_count', 'memberships_count', 'currency'], 'name'), $this->sortDirection())
            ->paginate($this->perPage);

        return view('brands::livewire.admin.brands-index', ['brands' => $brands]);
    }
}
