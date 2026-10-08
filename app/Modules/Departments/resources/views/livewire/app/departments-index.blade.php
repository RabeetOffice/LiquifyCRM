<div class="flex flex-col gap-6">
    <x-ui.page-header title="Departments" subtitle="Teams inside this brand. Leads, sales and targets are counted per department.">
        <x-slot:actions>
            @can('create', \App\Modules\Departments\Models\Department::class)
                <x-ui.button variant="primary" icon="heroicon-o-plus" :href="route('brand.departments.create', ['brand' => $brand->slug])">Create a department</x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.card flush>
        <div class="lq-toolbar">
            <div class="relative w-full sm:w-72">
                <x-heroicon-o-magnifying-glass class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 lq-muted" />
                <input type="search" wire:model.live.debounce.300ms="search" class="lq-input lq-input-sm" style="padding-inline-start: 2.25rem" placeholder="Search departments…">
            </div>
        </div>

        @if ($departments->isEmpty())
            <x-ui.empty title="No departments yet" description="Create a team such as Sales Team A, then add its Manager and Agents." icon="heroicon-o-rectangle-group">
                <x-slot:actions>
                    @can('create', \App\Modules\Departments\Models\Department::class)
                        <x-ui.button variant="primary" :href="route('brand.departments.create', ['brand' => $brand->slug])">Create a department</x-ui.button>
                    @endcan
                </x-slot:actions>
            </x-ui.empty>
        @else
            <div class="lq-table-wrap">
                <table class="lq-table">
                    <thead>
                        <tr>
                            <x-ui.sort-th field="name" :sort="$sort" :direction="$direction" label="Department" />
                            <th>Managers</th>
                            <x-ui.sort-th field="agents_count" :sort="$sort" :direction="$direction" label="Agents" align="end" />
                            <x-ui.sort-th field="created_at" :sort="$sort" :direction="$direction" label="Created" />
                            <th class="lq-table-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($departments as $department)
                            <tr wire:key="department-{{ $department->getKey() }}">
                                <td>
                                    <div class="flex items-center gap-3">
                                        <span class="h-3 w-3 flex-none rounded-full" style="background: {{ $department->color ?? 'var(--lq-orange)' }}"></span>
                                        <div class="min-w-0">
                                            <a href="{{ route('brand.departments.edit', ['brand' => $brand->slug, 'department' => $department]) }}" class="lq-cell-title" wire:navigate>{{ $department->name }}</a>
                                            @if ($department->description)
                                                <span class="lq-cell-sub">{{ str($department->description)->limit(80) }}</span>
                                            @endif
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    @forelse ($department->managers as $manager)
                                        <x-ui.badge color="info">{{ $manager->name }}</x-ui.badge>
                                    @empty
                                        <span class="lq-muted">No Manager yet</span>
                                    @endforelse
                                </td>
                                <td class="lq-table-end lq-num">{{ $department->agents_count }}</td>
                                <td class="lq-muted">{{ $department->created_at?->diffForHumans() }}</td>
                                <td class="lq-table-end">
                                    <div class="flex justify-end gap-1">
                                        @can('update', $department)
                                            <x-ui.button size="sm" variant="ghost" :href="route('brand.departments.edit', ['brand' => $brand->slug, 'department' => $department])" icon="heroicon-o-pencil-square">Edit</x-ui.button>
                                        @endcan
                                        @can('delete', $department)
                                            <x-ui.button size="sm" variant="ghost" wire:click="confirmDelete({{ $department->getKey() }})" icon="heroicon-o-trash">Remove</x-ui.button>
                                        @endcan
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            {{ $departments->links('pagination.lq') }}
        @endif
    </x-ui.card>

    <x-ui.confirm :confirm="$confirm" />
</div>
