<div class="flex flex-col gap-6">
    <x-ui.page-header title="Targets" subtitle="Monthly goals for the brand, each department and each person: revenue, number of sales, or both.">
        <x-slot:actions>
            <div class="lq-month">
                <button type="button" class="lq-btn lq-btn-ghost lq-btn-icon" wire:click="shift(-1)" title="Previous month"><x-heroicon-o-chevron-left class="h-5 w-5" /></button>
                <span class="lq-month-label">{{ $month->format('F Y') }}</span>
                <button type="button" class="lq-btn lq-btn-ghost lq-btn-icon" wire:click="shift(1)" title="Next month"><x-heroicon-o-chevron-right class="h-5 w-5" /></button>
            </div>
            @if ($canEdit)
                <x-ui.button variant="secondary" icon="heroicon-o-document-duplicate" wire:click="copyLastMonth" wire:loading.attr="disabled" wire:target="copyLastMonth">Copy last month</x-ui.button>
                <x-ui.button variant="primary" wire:click="save" wire:loading.attr="disabled" wire:target="save">Save targets</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.card flush>
        @if ($rows === [])
            <x-ui.empty title="No departments yet" description="Create a department and add its people; then set their targets here." icon="heroicon-o-flag">
                <x-slot:actions>
                    <x-ui.button variant="secondary" :href="route('brand.departments.index', ['brand' => $brand->slug])">Open Departments</x-ui.button>
                </x-slot:actions>
            </x-ui.empty>
        @else
            <form wire:submit="save">
                <div class="lq-table-wrap">
                    <table class="lq-table">
                        <thead>
                            <tr>
                                <th>Who</th>
                                <th style="width: 11rem">Revenue target ({{ $brand->currency }})</th>
                                <th style="width: 9rem">Sales target</th>
                                <th style="min-width: 16rem">Progress this month</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rows as $row)
                                <tr wire:key="target-{{ $row['key'] }}" @class(['lq-target-level-'.$row['level']])>
                                    <td>
                                        <div style="padding-inline-start: {{ $row['level'] === 2 ? '1.5rem' : '0' }}">
                                            <span class="lq-cell-title">{{ $row['name'] }}</span>
                                            <span class="lq-cell-sub">{{ $row['hint'] }}</span>
                                        </div>
                                    </td>
                                    <td>
                                        @if ($row['editable'])
                                            <input type="number" min="0" step="1" inputmode="decimal" class="lq-input lq-input-sm lq-tabular" wire:model="values.{{ $row['key'] }}.revenue" placeholder="—" aria-label="Revenue target for {{ $row['name'] }}">
                                        @else
                                            <span class="lq-num">{{ $row['progress']->targetRevenue !== null ? \App\Modules\Sales\Support\Money::format($row['progress']->targetRevenue, $brand->currency) : '—' }}</span>
                                        @endif
                                    </td>
                                    <td>
                                        @if ($row['editable'])
                                            <input type="number" min="0" step="1" inputmode="numeric" class="lq-input lq-input-sm lq-tabular" wire:model="values.{{ $row['key'] }}.count" placeholder="—" aria-label="Sales target for {{ $row['name'] }}">
                                        @else
                                            <span class="lq-num">{{ $row['progress']->targetCount ?? '—' }}</span>
                                        @endif
                                    </td>
                                    <td><x-sales::progress :progress="$row['progress']" compact /></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @error('values.*')
                    <p class="lq-error px-6 py-3">{{ $message }}</p>
                @enderror
            </form>
        @endif
    </x-ui.card>

    <p class="lq-help">Leave a row empty for no target. When both are filled, the bar follows revenue. Above 100% the bar becomes an Over-achieved badge and the person moves up the leaderboard.</p>
</div>
