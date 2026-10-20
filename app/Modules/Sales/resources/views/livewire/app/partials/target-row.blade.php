{{-- One line of the targets grid: who, the two targets (fields when the person may set them), and how this month is going.
     The column headings above the groups say what each field is (targets-page). --}}
@php
    use App\Modules\Sales\Support\Money;

    $progress = $row['progress'];
    $implied = $row['implied'] ?? null;
    $symbol = trim(Money::symbol($brand->currency));
@endphp
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
            <label class="lq-affix lq-trow-value" title="Value of sales to close in the month, in {{ $brand->currency }}">
                <span>{{ $symbol }}</span>
                <input type="number" min="0" step="1" inputmode="decimal" class="lq-tabular" wire:model="values.{{ $row['key'] }}.revenue" placeholder="{{ $implied?->targetRevenue ? number_format($implied->targetRevenue, 0, '.', '') : 'Value' }}" aria-label="Value of sales target for {{ $row['name'] }}">
            </label>
            <label class="lq-affix lq-trow-count" title="Number of sales to close in the month (optional)">
                <input type="number" min="0" step="1" inputmode="numeric" class="lq-tabular" wire:model="values.{{ $row['key'] }}.count" placeholder="Optional" aria-label="Number of sales target for {{ $row['name'] }}">
                <span>sales</span>
            </label>
        @else
            <span class="lq-trow-fixed lq-trow-value lq-tabular">{{ $progress->targetRevenue !== null ? Money::format($progress->targetRevenue, $brand->currency) : '—' }}</span>
            <span class="lq-trow-fixed lq-trow-count lq-tabular">{{ $progress->targetCount !== null ? $progress->targetCount.' '.str('sale')->plural($progress->targetCount) : '—' }}</span>
        @endif
    </div>

    <div class="lq-trow-progress">
        @if ($progress->hasTarget())
            <x-sales::progress :progress="$progress" compact />
            @unless ($progress->isOver())
                <span class="lq-trow-note">{{ $progress->summary() }}</span>
            @endunless
        @elseif ($implied !== null)
            {{-- No target of its own: measured against what its people's targets add up to. --}}
            <x-sales::progress :progress="$implied" compact class="lq-target-implied" />
            <span class="lq-trow-note">{{ $row['level'] === 0 ? 'Its teams\' targets' : 'Its people\'s targets' }} add up to {{ $implied->targetRevenue !== null ? Money::format($implied->targetRevenue, $brand->currency) : $implied->targetCount.' '.str('sale')->plural((int) $implied->targetCount) }}</span>
        @else
            <span class="lq-trow-none">{{ $progress->achieved() }}</span>
            <span class="lq-trow-note">No target for this month</span>
        @endif
    </div>
</div>
