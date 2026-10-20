{{-- The deal's amount with the currency it was sold in in front (ChoosesSaleCurrency). For a currency other than the brand's,
     a line under it says what it comes to in the brand's currency, which is what the targets count.
     Expects: $amountModel, $currencyModel, $label, $help, $brandCurrency, $currencyOptions, $dealRate, $dealRateMissing. --}}
<x-ui.field :label="$label" :name="$amountModel" required :help="$help">
    <div class="lq-input-group">
        @if ($currencyOptions !== [])
            <select class="lq-input-prefix lq-currency-pick" wire:model.live="{{ $currencyModel }}" aria-label="Currency it was sold in" title="The currency the client paid in">
                @foreach ($currencyOptions as $code => $name)
                    <option value="{{ $code }}" title="{{ $name }}">{{ $code }}</option>
                @endforeach
            </select>
        @else
            <span class="lq-input-prefix">{{ $brandCurrency }}</span>
        @endif
        <x-ui.input type="number" step="0.01" min="0" wire:model="{{ $amountModel }}" name="{{ $amountModel }}" inputmode="decimal" placeholder="1500" />
    </div>
</x-ui.field>

@if ($currencyOptions !== [] && $dealRate !== null)
    <p class="lq-deal-convert" x-data x-show="$wire.{{ $currencyModel }} !== @js($brandCurrency)">
        <x-heroicon-m-arrows-right-left />
        <span>
            <strong x-text="(Number($wire.{{ $amountModel }}) > 0 ? (Number($wire.{{ $amountModel }}) * {{ $dealRate }}).toLocaleString('en-GB', { style: 'currency', currency: @js($brandCurrency) }) : '…')"></strong>
            in {{ $brandCurrency }} for the targets, at today's rate of 1 <span x-text="$wire.{{ $currencyModel }}"></span> = {{ rtrim(rtrim(number_format($dealRate, 4, '.', ''), '0'), '.') }} {{ $brandCurrency }}. The close date's rate is used when it is saved.
        </span>
    </p>
@elseif ($currencyOptions !== [] && $dealRateMissing)
    <p class="lq-deal-convert lq-deal-convert-warn">
        <x-heroicon-m-exclamation-triangle />
        <span>The exchange rate could not be fetched just now. Try again in a minute, or record the sale in {{ $brandCurrency }}.</span>
    </p>
@endif
