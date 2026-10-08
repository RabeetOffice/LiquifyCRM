@props(['field', 'sort' => null, 'direction' => 'asc', 'label', 'align' => 'start'])
<th {{ $attributes->class(['lq-table-end' => $align === 'end']) }}>
    <button type="button" class="lq-sort" wire:click="sortBy('{{ $field }}')">
        {{ $label }}
        @if ($sort === $field)
            @if ($direction === 'asc')
                <x-heroicon-m-chevron-up />
            @else
                <x-heroicon-m-chevron-down />
            @endif
        @else
            <x-heroicon-m-chevron-up-down />
        @endif
    </button>
</th>
