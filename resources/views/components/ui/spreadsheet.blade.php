@props(['tabs' => [], 'tab' => 0, 'headers' => [], 'preview' => [], 'total' => 0, 'results' => [], 'used' => []])
{{-- An import's workbook (WithSpreadsheetImport): the open tab's rows, every tab along the bottom like in Excel. --}}
<div {{ $attributes->class(['lq-xls']) }}>
    <div class="lq-xls-grid" wire:loading.class="opacity-50" wire:target="openTab">
        @if ($headers === [])
            <p class="lq-xls-empty">This tab is empty.</p>
        @else
            <table>
                <thead>
                    <tr>
                        <th class="lq-xls-num" scope="col"><span class="sr-only">Row</span></th>
                        @foreach ($headers as $index => $header)
                            <th scope="col" @class(['lq-xls-used' => in_array((string) $index, $used, true)]) title="{{ $header }}">{{ $header !== '' ? $header : 'Column '.($index + 1) }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($preview as $number => $row)
                        <tr wire:key="xls-{{ $tab }}-{{ $number }}">
                            <td class="lq-xls-num">{{ $number + 1 }}</td>
                            @foreach ($headers as $index => $header)
                                @php($cell = (string) ($row[$index] ?? ''))
                                <td title="{{ mb_strlen($cell) > 40 ? $cell : '' }}">{{ $cell }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    @if ($total > count($preview))
        <p class="lq-xls-more">Showing the first {{ count($preview) }} of {{ number_format($total) }} rows. The import takes every row of the tab.</p>
    @endif

    <div class="lq-xls-tabs" role="tablist" aria-label="Tabs of the file">
        @foreach ($tabs as $index => $sheet)
            <button type="button" wire:key="xls-tab-{{ $index }}" wire:click="openTab({{ $index }})" role="tab" aria-selected="{{ $index === $tab ? 'true' : 'false' }}" @class([
                'lq-xls-tab',
                'lq-xls-tab-active' => $index === $tab,
                'lq-xls-tab-done' => isset($results[$index]),
                'lq-xls-tab-empty' => $sheet['rows'] === 0,
            ])>
                @isset($results[$index])
                    <x-heroicon-s-check-circle aria-label="Imported" />
                @endisset
                {{ $sheet['name'] }}
                <span class="lq-view-count">{{ number_format($sheet['read'] ? $sheet['rows'] : max($sheet['rows'] - 1, 0)) }}</span>
            </button>
        @endforeach
    </div>
</div>
