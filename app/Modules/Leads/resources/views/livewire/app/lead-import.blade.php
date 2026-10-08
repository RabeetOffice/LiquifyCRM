<div class="flex flex-col gap-4">
    @if ($result)
        <div class="lq-alert lq-alert-success">
            <x-heroicon-o-check-circle />
            <span><strong>{{ $result['created'] }}</strong> {{ str('lead')->plural($result['created']) }} imported, <strong>{{ $result['merged'] }}</strong> matched existing leads, <strong>{{ $result['failed'] }}</strong> failed.</span>
        </div>
        @if ($result['errors'] !== [])
            <ul class="lq-help list-disc pl-5">
                @foreach ($result['errors'] as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        @endif
        <div><x-ui.button size="sm" wire:click="reset_">Import another file</x-ui.button></div>
    @elseif ($headers === [])
        <x-ui.field label="File" name="file" help="CSV or Excel with a heading row. Up to 10 MB.">
            <input type="file" wire:model="file" accept=".csv,.txt,.xlsx,.xls" class="lq-input" style="padding: 0.375rem 0.625rem">
            <span class="lq-help" wire:loading wire:target="file">Reading the file…</span>
        </x-ui.field>
    @else
        <p class="text-sm">Match the columns of <strong>{{ $file?->getClientOriginalName() }}</strong> ({{ count($rows) }} {{ str('row')->plural(count($rows)) }}) to lead fields. Name is required.</p>

        <div class="lq-form-grid lq-form-grid-2">
            @foreach ($fields as $field => $label)
                <x-ui.field :label="$label" :name="'map.'.$field" :required="$field === 'name'">
                    <x-ui.select wire:model="map.{{ $field }}" placeholder="Skip" class="lq-input-sm">
                        @foreach ($headers as $index => $header)
                            <option value="{{ $index }}">{{ $header !== '' ? $header : 'Column '.($index + 1) }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>
            @endforeach
        </div>

        @if ($preview !== [])
            <div class="lq-table-wrap rounded-xl" style="box-shadow: 0 0 0 1px var(--lq-hairline)">
                <table class="lq-table">
                    <thead>
                        <tr>
                            @foreach ($headers as $index => $header)
                                <th>{{ $header !== '' ? $header : 'Column '.($index + 1) }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($preview as $row)
                            <tr>
                                @foreach ($headers as $index => $header)
                                    <td class="whitespace-nowrap text-xs">{{ str((string) ($row[$index] ?? ''))->limit(32) }}</td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        <div class="flex justify-end gap-2">
            <x-ui.button variant="ghost" wire:click="reset_">Choose another file</x-ui.button>
            <x-ui.button variant="primary" wire:click="import" wire:loading.attr="disabled" icon="heroicon-o-arrow-up-tray">Import {{ count($rows) }} {{ str('row')->plural(count($rows)) }}</x-ui.button>
        </div>
    @endif
</div>
