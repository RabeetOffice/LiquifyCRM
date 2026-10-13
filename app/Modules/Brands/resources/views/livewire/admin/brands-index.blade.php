<div class="flex flex-col gap-6" data-live-refresh>
    <x-ui.page-header title="Brands" subtitle="Each brand is a workspace for one business: its websites, Facebook Pages, leads and people.">
        <x-slot:actions>
            @can('create', \App\Modules\Brands\Models\Brand::class)
                <x-ui.button variant="primary" icon="heroicon-o-plus" :href="route('admin.brands.create')">Add brand</x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>

    @if ($summary['brands'] === 0)
        <x-ui.card>
            <x-ui.empty title="No brands yet" description="A brand is a workspace for one business. Create your first one to start collecting leads." icon="heroicon-o-building-storefront">
                <x-slot:actions>
                    <x-ui.button variant="primary" :href="route('admin.brands.create')">Add your first brand</x-ui.button>
                </x-slot:actions>
            </x-ui.empty>
        </x-ui.card>
    @else
        <div class="lq-minis">
            <x-ui.mini label="Brands" :value="$summary['brands']" icon="heroicon-o-building-storefront" />
            <x-ui.mini label="Active" :value="$summary['active'].' of '.$summary['brands']" icon="heroicon-o-check-badge" :tone="$summary['active'] === $summary['brands'] ? 'ok' : null" />
            <x-ui.mini label="People" :value="$summary['people']" icon="heroicon-o-users" hint="Managers and Agents across every brand" :href="route('admin.people.index')" />
            <x-ui.mini label="Leads this month" :value="number_format($summary['leads'])" icon="heroicon-o-inbox-arrow-down" />
        </div>

        <div class="lq-viewbar lq-viewbar-card">
            <div class="lq-views" role="tablist" aria-label="Which brands to show">
                <button type="button" @class(['lq-view', 'lq-view-active' => $active === '']) wire:click="$set('active', '')" role="tab">All</button>
                <button type="button" @class(['lq-view', 'lq-view-active' => $active === '1']) wire:click="$set('active', '1')" role="tab">Active</button>
                <button type="button" @class(['lq-view', 'lq-view-active' => $active === '0']) wire:click="$set('active', '0')" role="tab">Inactive</button>
            </div>
            <div class="lq-viewbar-end">
                <label class="lq-search">
                    <x-heroicon-o-magnifying-glass />
                    <input type="search" wire:model.live.debounce.300ms="search" placeholder="Search name, address or website" aria-label="Search brands">
                </label>
            </div>
        </div>

        @if ($brands->isEmpty())
            <x-ui.card>
                <x-ui.empty title="No brand matches" description="Try another search, or show all brands." icon="heroicon-o-magnifying-glass">
                    <x-slot:actions>
                        <x-ui.button variant="secondary" wire:click="showAll">Show all brands</x-ui.button>
                    </x-slot:actions>
                </x-ui.empty>
            </x-ui.card>
        @else
            <div class="lq-sitegrid">
                @foreach ($brands as $brand)
                    <article class="lq-site" wire:key="brand-{{ $brand->getKey() }}">
                        <header class="lq-site-head">
                            <x-ui.avatar :name="$brand->name" :src="$brand->avatarUrl()" :color="$brand->primaryColor()" :tile="$brand->tileColor()" size="lg" brand />
                            <div class="min-w-0 flex-1">
                                <a href="{{ $brand->url() }}" class="lq-site-name" wire:navigate>{{ $brand->name }}</a>
                                @if ($brand->website)
                                    <a href="{{ $brand->website }}" target="_blank" rel="noopener noreferrer" class="lq-site-domain">{{ parse_url($brand->website, PHP_URL_HOST) ?: $brand->website }} <x-heroicon-m-arrow-top-right-on-square /></a>
                                @else
                                    <span class="lq-site-domain">No website yet</span>
                                @endif
                            </div>
                            <span @class(['lq-state', 'lq-state-ok' => $brand->is_active])>
                                <span @class(['lq-health-dot', 'lq-health-ok' => $brand->is_active])></span>
                                {{ $brand->is_active ? 'Active' : 'Inactive' }}
                            </span>
                        </header>

                        <dl class="lq-brandfacts">
                            <div><dt>Leads this month</dt><dd>{{ number_format((int) ($leads[$brand->getKey()] ?? 0)) }}</dd></div>
                            <div><dt>Departments</dt><dd>{{ $brand->departments_count }}</dd></div>
                            <div><dt>People</dt><dd>{{ $brand->memberships_count }}</dd></div>
                            <div><dt>Currency</dt><dd>{{ $brand->currency }}</dd></div>
                        </dl>

                        <footer class="lq-site-foot">
                            <span class="lq-site-note mr-auto" title="The address of this brand">/b/{{ $brand->slug }}</span>
                            @can('delete', $brand)
                                <button type="button" class="lq-btn lq-btn-ghost lq-btn-icon lq-btn-icon-danger" wire:click="confirmDelete({{ $brand->getKey() }})" title="Remove" aria-label="Remove {{ $brand->name }}"><x-heroicon-o-trash /></button>
                            @endcan
                            @can('update', $brand)
                                <x-ui.button size="sm" variant="ghost" :href="route('admin.brands.edit', ['brandId' => $brand->getKey()])" icon="heroicon-o-pencil-square">Edit</x-ui.button>
                            @endcan
                            <x-ui.button size="sm" variant="secondary" :href="$brand->url()">Open</x-ui.button>
                        </footer>
                    </article>
                @endforeach
            </div>
            {{ $brands->links('pagination.lq') }}
        @endif
    @endif

    <x-ui.confirm :confirm="$confirm" />
</div>
