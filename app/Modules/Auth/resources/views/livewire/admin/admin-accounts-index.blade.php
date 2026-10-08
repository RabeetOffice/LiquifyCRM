<div class="flex flex-col gap-6">
    <x-ui.page-header title="Admin accounts" subtitle="Super Admins and Admins see every brand. Only a Super Admin can add or change them.">
        <x-slot:actions>
            <x-ui.button variant="primary" icon="heroicon-o-plus" :href="route('admin.admins.create')">Add admin</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.card flush>
        <div class="lq-toolbar">
            <div class="relative w-full sm:w-72">
                <x-heroicon-o-magnifying-glass class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 lq-muted" />
                <input type="search" wire:model.live.debounce.300ms="search" class="lq-input lq-input-sm" style="padding-inline-start: 2.25rem" placeholder="Search by name or email…">
            </div>
        </div>

        <div class="lq-table-wrap">
            <table class="lq-table">
                <thead>
                    <tr>
                        <x-ui.sort-th field="name" :sort="$sort" :direction="$direction" label="Name" />
                        <x-ui.sort-th field="role" :sort="$sort" :direction="$direction" label="Role" />
                        <th>Protected</th>
                        <th>2FA</th>
                        <th>Status</th>
                        <x-ui.sort-th field="last_login_at" :sort="$sort" :direction="$direction" label="Last sign-in" />
                        <th class="lq-table-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($admins as $admin)
                        <tr wire:key="admin-{{ $admin->getKey() }}">
                            <td>
                                <div class="flex items-center gap-3">
                                    <x-ui.avatar :name="$admin->name" :src="$admin->avatarUrl()" />
                                    <div class="min-w-0">
                                        <span class="lq-cell-title">{{ $admin->name }}</span>
                                        <span class="lq-cell-sub">{{ $admin->email }}</span>
                                    </div>
                                </div>
                            </td>
                            <td><x-ui.badge :color="$admin->role?->getColor() ?? 'gray'">{{ $admin->role?->getLabel() }}</x-ui.badge></td>
                            <td>
                                @if ($admin->is_protected)
                                    <x-ui.badge color="primary" icon="heroicon-m-shield-check">Protected</x-ui.badge>
                                @else
                                    <span class="lq-muted">—</span>
                                @endif
                            </td>
                            <td>
                                @if ($admin->hasTwoFactorEnabled())
                                    <x-ui.badge color="success" icon="heroicon-m-check">On</x-ui.badge>
                                @else
                                    <x-ui.badge color="warning">Not set up</x-ui.badge>
                                @endif
                            </td>
                            <td><x-ui.badge :color="$admin->status->getColor()">{{ $admin->status->getLabel() }}</x-ui.badge></td>
                            <td class="lq-muted">{{ $admin->last_login_at?->diffForHumans() ?? 'never' }}</td>
                            <td class="lq-table-end">
                                <div class="flex justify-end gap-1">
                                    @can('update', $admin)
                                        <x-ui.button size="sm" variant="ghost" :href="route('admin.admins.edit', ['userId' => $admin->getKey()])" icon="heroicon-o-pencil-square">Edit</x-ui.button>
                                    @endcan
                                    @can('suspend', $admin)
                                        <x-ui.button size="sm" variant="ghost" wire:click="confirmToggle({{ $admin->getKey() }})" :icon="$admin->isSuspended() ? 'heroicon-o-check-circle' : 'heroicon-o-no-symbol'">{{ $admin->isSuspended() ? 'Reactivate' : 'Suspend' }}</x-ui.button>
                                    @endcan
                                    @can('delete', $admin)
                                        <x-ui.button size="sm" variant="ghost" wire:click="confirmDelete({{ $admin->getKey() }})" icon="heroicon-o-trash">Remove</x-ui.button>
                                    @endcan
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        {{ $admins->links('pagination.lq') }}
    </x-ui.card>

    <x-ui.confirm :confirm="$confirm" />
</div>
