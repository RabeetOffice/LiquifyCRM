<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A monthly exchange rate entered by the Admin: how much 1 unit of a
 * currency is worth in the reporting currency (GBP by default).
 *
 * @property int $id
 * @property string $period
 * @property string $currency
 * @property string $rate_to_reporting
 * @property int|null $set_by
 */
class ExchangeRate extends Model
{
    protected $fillable = ['period', 'currency', 'rate_to_reporting', 'set_by'];

    protected function casts(): array
    {
        return ['rate_to_reporting' => 'decimal:6'];
    }

    public static function reportingCurrency(): string
    {
        return (string) config('liquify.reporting_currency', 'GBP');
    }

    /**
     * The rate for a month: that month's own rate, else the latest earlier
     * one. Null when the Admin has not entered any rate for the currency yet.
     */
    public static function rateFor(string $currency, string $period): ?float
    {
        if ($currency === self::reportingCurrency()) {
            return 1.0;
        }

        $rate = self::query()
            ->where('currency', $currency)
            ->where('period', '<=', $period)
            ->orderByDesc('period')
            ->value('rate_to_reporting');

        return $rate === null ? null : (float) $rate;
    }

    public static function convert(float|string $amount, string $currency, string $period): ?string
    {
        $rate = self::rateFor($currency, $period);

        return $rate === null ? null : number_format((float) $amount * $rate, 2, '.', '');
    }
}
