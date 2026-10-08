<div class="flex flex-col gap-6">
    <x-ui.page-header title="Exchange rates" subtitle="Each brand sells in its own currency. Totals across brands are shown in {{ $reporting }} using the rate you enter for each month.">
        <x-slot:actions>
            <div class="lq-month">
                <button type="button" class="lq-btn lq-btn-ghost lq-btn-icon" wire:click="shift(-1)" title="Previous month"><x-heroicon-o-chevron-left class="h-5 w-5" /></button>
                <span class="lq-month-label">{{ $month->format('F Y') }}</span>
                <button type="button" class="lq-btn lq-btn-ghost lq-btn-icon" wire:click="shift(1)" title="Next month" @disabled($isCurrent)><x-heroicon-o-chevron-right class="h-5 w-5" /></button>
            </div>
        </x-slot:actions>
    </x-ui.page-header>

    @if ($unconverted > 0)
        <div class="lq-alert lq-alert-warning">
            <x-heroicon-o-exclamation-triangle />
            <span>{{ $unconverted }} {{ str('sale')->plural($unconverted) }} {{ $unconverted === 1 ? 'is' : 'are' }} missing from the cross-brand totals because no rate is entered for {{ $unconverted === 1 ? 'its' : 'their' }} currency. Saving a rate converts them.</span>
        </div>
    @endif

    <form wire:submit="save">
        <x-ui.card title="Rates for {{ $month->format('F Y') }}" description="How much 1 unit of each currency is worth in {{ $reporting }}. A month without its own rate uses the latest earlier one.">
            <div class="lq-form-grid lq-form-grid-2">
                @foreach ($currencies as $currency)
                    <x-ui.field :label="'1 '.$currency.' ='" :name="'rates.'.$currency"
                        :help="($rates[$currency] ?? '') === '' ? ($effective[$currency] !== null ? 'Using '.rtrim(rtrim(number_format($effective[$currency], 6), '0'), '.').' from an earlier month.' : (in_array($currency, $inUse, true) ? 'No rate yet: a brand sells in '.$currency.'.' : 'Not used by any brand yet.')) : null">
                        <div class="lq-input-group">
                            <x-ui.input type="number" step="0.000001" min="0" inputmode="decimal" wire:model="rates.{{ $currency }}" :name="'rates.'.$currency" placeholder="0.52" class="lq-tabular" />
                            <span class="lq-input-prefix">{{ $reporting }}</span>
                        </div>
                    </x-ui.field>
                @endforeach
            </div>

            <x-slot:footer>
                <x-ui.button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="save">Save rates</x-ui.button>
            </x-slot:footer>
        </x-ui.card>
    </form>
</div>
