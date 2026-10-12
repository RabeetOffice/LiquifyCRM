{{-- One line of the targets grid: who, the two targets (fields when the person may set them), and progress this month. --}}
@php use App\Modules\Sales\Support\Money; @endphp
<div @class(['lq-trow', 'lq-trow-head' => $row['level'] < 2]) wire:key="target-{{ $row['key'] }}">
    <div class="lq-person lq-trow-who">
        @if ($row['level'] === 2)
            <x-ui.avatar :name="$row['name']" size="sm" />
        @else
            <span class="lq-trow-mark">@svg($row['level'] === 0 ? 'heroicon-o-building-storefront' : 'heroicon-o-rectangle-group')</span>
        @endif
        <span class="min-w-0">
            <span class="lq-cell-title">{{ $row['name'] }}</span>
            <span class="lq-cell-sub">{{ $row['hint'] }}</span>
        </span>
    </div>

    <div class="lq-trow-fields">
        @if ($row['editable'])
            <label class="lq-affix" title="Revenue target for the month">
                <span>{{ trim(Money::symbol($brand->currency)) }}</span>
                <input type="number" min="0" step="1" inputmode="decimal" class="lq-tabular" wire:model="values.{{ $row['key'] }}.revenue" placeholder="Revenue" aria-label="Revenue target for {{ $row['name'] }}">
            </label>
            <label class="lq-affix lq-affix-short" title="Number of sales for the month">
                <input type="number" min="0" step="1" inputmode="numeric" class="lq-tabular" wire:model="values.{{ $row['key'] }}.count" placeholder="–" aria-label="Sales target for {{ $row['name'] }}">
                <span>sales</span>
            </label>
        @else
            <span class="lq-trow-fixed lq-tabular">{{ $row['progress']->targetRevenue !== null ? Money::format($row['progress']->targetRevenue, $brand->currency) : 'No revenue target' }}</span>
            <span class="lq-trow-fixed lq-tabular">{{ $row['progress']->targetCount !== null ? $row['progress']->targetCount.' '.str('sale')->plural($row['progress']->targetCount) : 'No sales target' }}</span>
        @endif
    </div>

    <div class="lq-trow-progress">
        <x-sales::progress :progress="$row['progress']" compact />
    </div>
</div>
