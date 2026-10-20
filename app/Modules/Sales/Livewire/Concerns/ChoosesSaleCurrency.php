<?php

declare(strict_types=1);

namespace App\Modules\Sales\Livewire\Concerns;

use App\Modules\Sales\Support\ExchangeRates;

/**
 * The currency a sale was made in (user request, 20 Oct 2026), for the sale
 * and upsell sheets: the brand's by default, any other the European Central
 * Bank publishes. The sheet shows what the deal comes to in the brand's
 * currency with today's rate (`rate`); the action converts with the rate of
 * the close date. Used with the sales::partials.deal-amount field.
 */
trait ChoosesSaleCurrency
{
    /** 1 unit of the chosen currency in the brand's, for the "≈" line; null for the brand's own or when unknown. */
    public ?float $dealRate = null;

    /** The rate could not be fetched: the sheet says so. */
    public bool $dealRateMissing = false;

    /** Called when the currency field changes. */
    protected function lookUpDealRate(string $currency, string $brandCurrency): void
    {
        $currency = strtoupper($currency);
        $this->dealRateMissing = false;

        if ($currency === '' || $currency === strtoupper($brandCurrency) || ! ExchangeRates::supports($currency)) {
            $this->dealRate = null;

            return;
        }

        $this->dealRate = app(ExchangeRates::class)->rate($currency, $brandCurrency);
        $this->dealRateMissing = $this->dealRate === null;
    }

    /** @return array<string, string> the brand's currency first, then the others */
    protected function dealCurrencyOptions(string $brandCurrency): array
    {
        if (! ExchangeRates::ready()) {
            return [];
        }

        $brandCurrency = strtoupper($brandCurrency);
        $all = ExchangeRates::CURRENCIES;

        return [$brandCurrency => $all[$brandCurrency] ?? $brandCurrency, ...array_diff_key($all, [$brandCurrency => true])];
    }
}
