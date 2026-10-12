<div class="flex flex-col gap-6" data-live-refresh>
    <x-ui.page-header title="People" subtitle="Everyone who can sign in. Admins see every brand; Managers and Agents work inside the brand and department you give them.">
        <x-slot:actions>
            @can('create', \App\Modules\Auth\Models\User::class)
                <x-ui.button variant="primary" icon="heroicon-o-user-plus" :href="route('admin.people.create')">Add person</x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="lq-tabs" role="tablist">
            @foreach (['all' => 'Everyone', 'admins' => 'Admins', 'managers' => 'Managers', 'agents' => 'Agents'] as $key => $label)
                <button type="button" role="tab" wire:click="$set('tab', '{{ $key }}')" @class(['lq-tab', 'lq-tab-active' => $tab === $key]) aria-selected="{{ $tab === $key ? 'true' : 'false' }}">
                    {{ $label }} <span class="lq-muted lq-tabular">{{ $counts[$key] }}</span>
                </button>
            @endforeach
        </div>

        <div class="flex flex-wrap items-center gap-2">
            @if ($brands !== [])
                <x-ui.select wire:model.live="brandFilter" placeholder="Every brand" class="lq-input-sm" style="width: 12rem" aria-label="Brand">
                    @foreach ($brands as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </x-ui.select>
            @endif
            <div class="relative w-full sm:w-64">
                <x-heroicon-o-magnifying-glass class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 lq-muted" />
                <input type="search" wire:model.live.debounce.300ms="search" class="lq-input lq-input-sm" style="padding-inline-start: 2.25rem" placeholder="Name, email or designation…">
            </div>
        </div>
    </div>

    <x-ui.card flush>
        @if ($people->isEmpty())
            <x-ui.empty title="Nobody here yet" description="Add a Manager or an Agent and place them in a brand, or add another Admin." icon="heroicon-o-users">
                <x-ui.button variant="primary" icon="heroicon-o-user-plus" :href="route('admin.people.create')">Add person</x-ui.button>
            </x-ui.empty>
        @else
            <div class="lq-table-wrap">
                <table class="lq-table lq-table-rows">
                    <thead>
                        <tr>
                            <x-ui.sort-th field="name" :sort="$sort" :direction="$direction" label="Person" />
                            <th>Role</th>
                            <th>Works in</th>
                            <th>Status</th>
                            <x-ui.sort-th field="last_login_at" :sort="$sort" :direction="$direction" label="Last sign-in" class="lq-col-wide" />
                            <th class="lq-table-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($people as $person)
                            @php
                                $memberships = $person->memberships;
                                $firstRole = $memberships->first()?->role;
                            @endphp
                            <tr wire:key="person-{{ $person->getKey() }}">
                                <td class="lq-td-person">
                                    <span class="lq-person">
                                        <x-ui.avatar :name="$person->name" :src="$person->avatarUrl()" />
                                        <span class="min-w-0">
                                            <span class="lq-cell-title">{{ $person->name }}</span>
                                            <span class="lq-cell-sub" title="{{ $person->email }}">{{ $person->designation ? $person->designation.' · ' : '' }}{{ $person->email }}</span>
                                        </span>
                                    </span>
                                </td>
                                <td>
                                    @if ($person->role)
                                        <x-ui.badge :color="$person->role->getColor()" :icon="$person->isSuperAdmin() ? 'heroicon-m-shield-check' : null">{{ $person->role->getLabel() }}</x-ui.badge>
                                    @elseif ($firstRole)
                                        <x-ui.badge :color="$firstRole->getColor()">{{ $firstRole->getLabel() }}</x-ui.badge>
                                    @else
                                        <span class="lq-muted">No role yet</span>
                                    @endif
                                </td>
                                <td>
                                    @if ($person->isGlobalAdmin())
                                        <span class="lq-muted">Every brand</span>
                                    @elseif ($memberships->isEmpty())
                                        <span class="lq-muted">—</span>
                                    @else
                                        <div class="flex flex-wrap items-center gap-1.5">
                                            @foreach ($memberships->take(3) as $membership)
                                                @if ($membership->brand)
                                                    <a href="{{ route('brand.members.index', ['brand' => $membership->brand->slug]) }}" class="lq-badge lq-badge-brand" title="{{ $membership->role->getLabel() }} in {{ $membership->brand->name }}" wire:navigate>
                                                        <x-ui.avatar :name="$membership->brand->name" :src="$membership->brand->avatarUrl()" :color="$membership->brand->primaryColor()" size="xs" brand />
                                                        {{ $membership->brand->name }}
                                                    </a>
                                                @endif
                                            @endforeach
                                            @if ($memberships->count() > 3)
                                                <span class="lq-muted text-xs">+{{ $memberships->count() - 3 }}</span>
                                            @endif
                                        </div>
                                    @endif
                                </td>
                                <td><x-ui.badge :color="$person->status->getColor()">{{ $person->status->getLabel() }}</x-ui.badge></td>
                                <td class="lq-muted whitespace-nowrap lq-col-wide">{{ $person->last_login_at?->diffForHumans() ?? 'never' }}</td>
                                <td class="lq-table-end">
                                    <div class="lq-row-tools">
                                        @can('update', $person)
                                            <x-ui.button size="sm" variant="ghost" :href="route('admin.people.edit', ['userId' => $person->getKey()])" icon="heroicon-o-pencil-square">Edit</x-ui.button>
                                        @endcan
                                        @can('suspend', $person)
                                            @php $toggle = $person->isSuspended() ? 'Reactivate' : 'Suspend'; @endphp
                                            <button type="button" class="lq-btn lq-btn-ghost lq-btn-icon" wire:click="confirmToggle({{ $person->getKey() }})" title="{{ $toggle }}" aria-label="{{ $toggle }} {{ $person->name }}">@svg($person->isSuspended() ? 'heroicon-o-check-circle' : 'heroicon-o-no-symbol')</button>
                                        @endcan
                                        @can('delete', $person)
                                            <button type="button" class="lq-btn lq-btn-ghost lq-btn-icon lq-btn-icon-danger" wire:click="confirmDelete({{ $person->getKey() }})" title="Remove" aria-label="Remove {{ $person->name }}"><x-heroicon-o-trash /></button>
                                        @endcan
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            {{ $people->links('pagination.lq') }}
        @endif
    </x-ui.card>

    <x-ui.confirm :confirm="$confirm" />
</div>
