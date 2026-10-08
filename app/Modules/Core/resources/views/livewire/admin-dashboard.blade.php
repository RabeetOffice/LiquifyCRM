<div class="flex flex-col gap-6">
    <x-ui.page-header title="Overview" subtitle="Here is the summary across all brands.">
        <x-slot:actions>
            @can('create', \App\Modules\Brands\Models\Brand::class)
                <x-ui.button variant="primary" icon="heroicon-o-plus" :href="route('admin.brands.create')">Add brand</x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>

    <div class="lq-grid-stats">
        <x-ui.stat label="Active brands" :value="$brands" icon="heroicon-o-building-storefront" :description="$brands === 0 ? 'Add your first brand to begin' : 'Workspaces collecting leads'" :href="route('admin.brands.index')" link="Manage brands" hero />
        <x-ui.stat label="Leads this month" :value="$leadsThisMonth" icon="heroicon-o-inbox-arrow-down" description="All brands, vs last month" :delta="$leadsDelta['text'] ?? null" :up="$leadsDelta['up'] ?? null" />
        <x-ui.stat label="Departments" :value="$departments" icon="heroicon-o-rectangle-group" description="Teams across all brands" />
        <x-ui.stat label="Managers and Agents" :value="$managers.' / '.$agents" icon="heroicon-o-user-group" description="People working leads" />
    </div>

    <div class="lq-grid-main">
        <x-ui.card title="Leads per brand" description="Last 30 days">
            @if ($chart['values'] === [])
                <x-ui.empty title="No leads yet" description="Leads added by hand or imported in a brand show up here; website and Facebook sources arrive in weeks 3 and 4." icon="heroicon-o-chart-bar" />
            @else
                <div class="lq-chart" wire:ignore x-data="chart({
                    type: 'bar',
                    data: {
                        labels: @js($chart['labels']),
                        datasets: [{ label: 'Leads', data: @js($chart['values']), backgroundColor: '#ff6b2c', borderRadius: 6, maxBarThickness: 36 }]
                    },
                    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } }, x: { grid: { display: false } } } }
                })">
                    <canvas x-ref="canvas"></canvas>
                </div>
            @endif
        </x-ui.card>

        <div class="flex flex-col gap-6">
            @if ($pendingMigrations !== null)
                <x-ui.stat label="Database updates" :value="$pendingMigrations" icon="heroicon-o-circle-stack" :description="$pendingMigrations > 0 ? 'Open System to apply them' : 'Database is up to date'" :description-color="$pendingMigrations > 0 ? 'warning' : 'success'" :description-icon="$pendingMigrations > 0 ? 'heroicon-o-exclamation-triangle' : 'heroicon-o-check-circle'" :href="route('admin.system')" link="Open System" />
            @endif

            <x-ui.card title="Recent activity" description="Latest entries in the audit trail" flush>
                @if ($activity->isEmpty())
                    <p class="lq-help px-6 py-6">Nothing logged yet.</p>
                @else
                    <ul class="flex flex-col">
                        @foreach ($activity as $entry)
                            <li class="flex items-start gap-3 px-6 py-3" style="border-top: 1px solid var(--lq-hairline)">
                                <x-ui.avatar :name="$entry->causer?->name ?? 'System'" size="sm" />
                                <div class="min-w-0 flex-1">
                                    <div class="truncate text-sm">{{ $entry->description }}</div>
                                    <div class="text-xs lq-muted">{{ $entry->causer?->name ?? 'System' }}{{ $entry->brand ? ' · '.$entry->brand->name : '' }} · {{ $entry->created_at?->diffForHumans() }}</div>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-ui.card>
        </div>
    </div>
</div>
