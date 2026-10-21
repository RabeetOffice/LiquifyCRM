{{-- An import (WithSpreadsheetImport) in its own dialog, opened by the window event lq-open-import: the file, then per
     tab the columns matched to fields, its rows and the button. --}}
@php
    $current = $tabs[$tab] ?? null;
    $result = $results[$tab] ?? null;
    $map = $maps[$tab] ?? [];
    $several = count($tabs) > 1;
    $plural = fn (int $count, string $word): string => number_format($count).' '.str($word)->plural($count);
@endphp
<div x-data x-on:lq-open-import.window="$wire.open = true">
    <x-ui.modal model="open" :title="$title" :description="$description" size="xl">
        <div class="flex flex-col gap-4">
            @if ($tabs === [])
                <x-ui.field label="File" name="file" help="CSV or Excel with a heading row. Every tab of an Excel file can be imported, one after the other. Up to 10 MB.">
                    <input type="file" wire:model="file" accept=".csv,.txt,.xlsx,.xls" class="lq-input" style="padding: 0.375rem 0.625rem">
                    <span class="lq-help" wire:loading wire:target="file">Reading the file…</span>
                </x-ui.field>
            @else
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <p class="flex min-w-0 items-center gap-2 text-sm">
                        <x-heroicon-o-table-cells class="size-5 shrink-0 text-[var(--lq-muted)]" />
                        <strong class="truncate">{{ $file?->getClientOriginalName() }}</strong>
                        @if ($several)
                            <span class="lq-help shrink-0">{{ $plural(count($tabs), 'tab') }} · {{ count($results) }} imported</span>
                        @endif
                    </p>
                    <x-ui.button size="sm" variant="ghost" wire:click="resetImport" icon="heroicon-o-arrow-path">Choose another file</x-ui.button>
                </div>

                @error('file')
                    <p class="lq-error">{{ $message }}</p>
                @enderror

                @if ($result)
                    <div class="lq-alert lq-alert-success">
                        <x-heroicon-o-check-circle />
                        <span>
                            @if ($several)<strong>{{ $current['name'] }}</strong>: @endif
                            <strong>{{ $result['created'] }}</strong> {{ str($noun)->plural($result['created']) }} added, <strong>{{ $result['merged'] }}</strong> matched existing {{ str($noun)->plural() }}, <strong>{{ $result['failed'] }}</strong> failed.
                            @if ($several && count($results) < count($tabs)) Open the next tab below. @endif
                        </span>
                    </div>
                    @if ($result['errors'] !== [])
                        <ul class="lq-help list-disc pl-5">
                            @foreach ($result['errors'] as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    @endif
                @endif

                @if ($headers !== [])
                    <p class="text-sm">
                        Match the columns of {{ $several ? 'the tab' : 'the file' }} @if ($several)<strong>{{ $current['name'] }}</strong>@endif to {{ $noun }} fields. {{ $help }}
                    </p>

                    <div class="lq-form-grid lq-form-grid-3" wire:key="map-{{ $tab }}">
                        @foreach ($fields as $field => $label)
                            <x-ui.field :label="$label" :name="'maps.'.$tab.'.'.$field" :required="in_array($field, $required, true)">
                                <x-ui.select wire:model.live="maps.{{ $tab }}.{{ $field }}" placeholder="Skip" class="lq-input-sm">
                                    @foreach ($headers as $index => $header)
                                        <option value="{{ $index }}">{{ $header !== '' ? $header : 'Column '.($index + 1) }}</option>
                                    @endforeach
                                </x-ui.select>
                            </x-ui.field>
                        @endforeach
                    </div>
                @endif

                <x-ui.spreadsheet :tabs="$tabs" :tab="$tab" :headers="$headers" :preview="$preview" :total="$total" :results="$results" :used="array_values(array_filter($map, fn ($column) => $column !== ''))" />

                <div class="flex flex-wrap items-center justify-end gap-2">
                    @if ($total > 0)
                        <x-ui.button :variant="$result ? 'secondary' : 'primary'" wire:click="import" wire:loading.attr="disabled" wire:target="import,openTab" icon="heroicon-o-arrow-up-tray">
                            <span wire:loading.remove wire:target="import">{{ $result ? 'Import again' : 'Import' }} {{ $plural($total, 'row') }}@if ($several) from {{ $current['name'] }}@endif</span>
                            <span wire:loading wire:target="import">Importing…</span>
                        </x-ui.button>
                    @endif
                </div>
            @endif
        </div>
    </x-ui.modal>
</div>
