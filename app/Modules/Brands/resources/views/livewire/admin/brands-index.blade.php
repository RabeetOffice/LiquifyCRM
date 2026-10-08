<div class="flex flex-col gap-6">
    <x-ui.page-header title="Brands" subtitle="Each brand is a workspace for one business: its websites, Facebook Pages, leads and people.">
        <x-slot:actions>
            @can('create', \App\Modules\Brands\Models\Brand::class)
                <x-ui.button variant="primary" icon="heroicon-o-plus" :href="route('admin.brands.create')">Add brand</x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.card flush>
        <div class="lq-toolbar">
            <div class="relative w-full sm:w-72">
                <x-heroicon-o-magnifying-glass class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 lq-muted" />
                <input type="search" wire:model.live.debounce.300ms="search" class="lq-input lq-input-sm" style="padding-inline-start: 2.25rem" placeholder="Search brands…">
            </div>
            <div class="lq-toolbar-end">
                <select wire:model.live="active" class="lq-input lq-input-sm" style="width: 10rem">
                    <option value="">All brands</option>
                    <option value="1">Active</option>
                    <option value="0">Inactive</option>
                </select>
            </div>
        </div>

        @if ($brands->isEmpty())
            <x-ui.empty title="No brands yet" description="A brand is a workspace for one business. Create your first one to start collecting leads." icon="heroicon-o-building-storefront">
                <x-slot:actions>
                    <x-ui.button variant="primary" :href="route('admin.brands.create')">Add your first brand</x-ui.button>
                </x-slot:actions>
            </x-ui.empty>
        @else
            <div class="lq-table-wrap">
                <table class="lq-table">
                    <thead>
                        <tr>
                            <x-ui.sort-th field="name" :sort="$sort" :direction="$direction" label="Brand" />
                            <th>Website</th>
                            <x-ui.sort-th field="currency" :sort="$sort" :direction="$direction" label="Currency" />
                            <x-ui.sort-th field="departments_count" :sort="$sort" :direction="$direction" label="Departments" align="end" />
                            <x-ui.sort-th field="memberships_count" :sort="$sort" :direction="$direction" label="People" align="end" />
                            <th>Status</th>
                            <th class="lq-table-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($brands as $brand)
                            <tr wire:key="brand-{{ $brand->getKey() }}">
                                <td>
                                    <div class="flex items-center gap-3">
                                        <x-ui.avatar :name="$brand->name" :src="$brand->avatarUrl()" :color="$brand->primaryColor()" brand />
                                        <div class="min-w-0">
                                            <a href="{{ route('admin.brands.edit', ['brandId' => $brand->getKey()]) }}" class="lq-cell-title" wire:navigate>{{ $brand->name }}</a>
                                            <span class="lq-cell-sub">/b/{{ $brand->slug }}</span>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    @if ($brand->website)
                                        <a href="{{ $brand->website }}" target="_blank" rel="noopener" class="lq-muted hover:underline">{{ parse_url($brand->website, PHP_URL_HOST) ?: $brand->website }}</a>
                                    @else
                                        <span class="lq-muted">—</span>
                                    @endif
                                </td>
                                <td><x-ui.badge>{{ $brand->currency }}</x-ui.badge></td>
                                <td class="lq-table-end lq-num">{{ $brand->departments_count }}</td>
                                <td class="lq-table-end lq-num">{{ $brand->memberships_count }}</td>
                                <td>
                                    @if ($brand->is_active)
                                        <x-ui.badge color="success">Active</x-ui.badge>
                                    @else
                                        <x-ui.badge>Inactive</x-ui.badge>
                                    @endif
                                </td>
                                <td class="lq-table-end">
                                    <div class="flex justify-end gap-1">
                                        <x-ui.button size="sm" variant="ghost" :href="$brand->url()" icon="heroicon-o-arrow-top-right-on-square">Open</x-ui.button>
                                        @can('update', $brand)
                                            <x-ui.button size="sm" variant="ghost" :href="route('admin.brands.edit', ['brandId' => $brand->getKey()])" icon="heroicon-o-pencil-square">Edit</x-ui.button>
                                        @endcan
                                        @can('delete', $brand)
                                            <x-ui.button size="sm" variant="ghost" wire:click="confirmDelete({{ $brand->getKey() }})" icon="heroicon-o-trash">Remove</x-ui.button>
                                        @endcan
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            {{ $brands->links('pagination.lq') }}
        @endif
    </x-ui.card>

    <x-ui.confirm :confirm="$confirm" />
</div>
