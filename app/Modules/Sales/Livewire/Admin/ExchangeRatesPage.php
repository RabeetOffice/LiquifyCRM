<?php

declare(strict_types=1);

namespace App\Modules\Sales\Livewire\Admin;

use App\Modules\Brands\Models\Brand;
use App\Modules\Core\Livewire\Concerns\WithToasts;
use App\Modules\Core\Support\Audit;
use App\Modules\Sales\Models\ExchangeRate;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Support\SalesReport;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Monthly exchange rates: each brand keeps its own currency, cross-brand
 * totals use one reporting currency (GBP by default) at the rate the Admin
 * enters here for the month.
 */
#[Layout('components.layouts.app')]
#[Title('Exchange rates')]
final class ExchangeRatesPage extends Component
{
    use WithToasts;

    #[Url(except: '')]
    public string $period = '';

    /** @var array<string, string> currency => rate */
    public array $rates = [];

    public function mount(): void
    {
        $this->fill_();
    }

    public function shift(int $months): void
    {
        $next = CarbonImmutable::createFromFormat('!Y-m', $this->currentPeriod())->addMonths($months);

        if ($next->lte(now()->endOfMonth())) {
            $this->period = $next->format('Y-m') === SalesReport::currentPeriod() ? '' : $next->format('Y-m');
            $this->fill_();
        }
    }

    public function save(): void
    {
        $this->validate(['rates.*' => ['nullable', 'numeric', 'gt:0', 'max:100000']], attributes: ['rates.*' => 'rate']);

        $period = $this->currentPeriod();

        foreach ($this->currencies() as $currency) {
            $rate = $this->rates[$currency] ?? '';

            if ($rate === '') {
                ExchangeRate::query()->where('period', $period)->where('currency', $currency)->delete();

                continue;
            }

            ExchangeRate::query()->updateOrCreate(['period' => $period, 'currency' => $currency], ['rate_to_reporting' => (float) $rate, 'set_by' => auth()->id()]);
        }

        $updated = $this->reconvert();

        Audit::log('settings.exchange_rates_updated', null, ['period' => $period, 'rates' => array_filter($this->rates, fn (string $rate): bool => $rate !== '')], 'Exchange rates set for '.$this->monthLabel(), logName: 'system');

        $this->toastSuccess('Exchange rates saved for '.$this->monthLabel(), $updated > 0 ? $updated.' '.str('sale')->plural($updated).' converted again.' : null);
    }

    public function render(): View
    {
        $period = $this->currentPeriod();

        return view('sales::livewire.admin.exchange-rates-page', [
            'reporting' => ExchangeRate::reportingCurrency(),
            'currencies' => $this->currencies(),
            'month' => CarbonImmutable::createFromFormat('!Y-m', $period),
            'isCurrent' => $period === SalesReport::currentPeriod(),
            'inUse' => Brand::query()->pluck('currency')->unique()->values()->all(),
            'effective' => collect($this->currencies())->mapWithKeys(fn (string $currency): array => [$currency => ExchangeRate::rateFor($currency, $period)])->all(),
            'unconverted' => Sale::query()->acrossBrands()->whereNull('amount_reporting')->count(),
        ]);
    }

    private function currentPeriod(): string
    {
        return SalesReport::cleanPeriod($this->period);
    }

    private function monthLabel(): string
    {
        return CarbonImmutable::createFromFormat('!Y-m', $this->currentPeriod())->format('F Y');
    }

    /** @return list<string> every currency except the reporting one */
    private function currencies(): array
    {
        return array_values(array_diff((array) config('liquify.currencies'), [ExchangeRate::reportingCurrency()]));
    }

    private function fill_(): void
    {
        $saved = ExchangeRate::query()->where('period', $this->currentPeriod())->pluck('rate_to_reporting', 'currency');

        $this->rates = [];

        foreach ($this->currencies() as $currency) {
            $this->rates[$currency] = $saved->has($currency) ? rtrim(rtrim((string) $saved->get($currency), '0'), '.') : '';
        }
    }

    /** Sales from this month on (and any never converted) take the new rates. */
    private function reconvert(): int
    {
        $start = CarbonImmutable::createFromFormat('!Y-m', $this->currentPeriod());
        $updated = 0;

        Sale::query()->acrossBrands()
            ->where('currency', '!=', ExchangeRate::reportingCurrency())
            ->where(fn ($query) => $query->where('closed_at', '>=', $start)->orWhereNull('amount_reporting'))
            ->chunkById(500, function ($sales) use (&$updated): void {
                foreach ($sales as $sale) {
                    $converted = ExchangeRate::convert($sale->amount, $sale->currency, $sale->closed_at->format('Y-m'));

                    if ($converted !== $sale->amount_reporting) {
                        $sale->forceFill(['amount_reporting' => $converted])->save();
                        $updated++;
                    }
                }
            });

        return $updated;
    }
}
