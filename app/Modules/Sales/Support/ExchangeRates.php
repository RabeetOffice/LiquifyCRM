<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Throwable;

/**
 * Exchange rates for a sale made in another currency (user request, 20 Oct
 * 2026). The daily reference rates of the European Central Bank, read from
 * the free Frankfurter service: only two currency codes and a date are sent,
 * nothing about anybody. A day's rate is kept (it does not change); when the
 * service cannot be reached, the last rate seen for the pair is used.
 */
final class ExchangeRates
{
    public const SOURCE = 'https://api.frankfurter.dev/v1';

    /** What the European Central Bank publishes, the usual ones first. */
    public const CURRENCIES = [
        'GBP' => 'British pound', 'EUR' => 'Euro', 'USD' => 'US dollar', 'AUD' => 'Australian dollar',
        'NZD' => 'New Zealand dollar', 'CAD' => 'Canadian dollar', 'CHF' => 'Swiss franc', 'SGD' => 'Singapore dollar',
        'HKD' => 'Hong Kong dollar', 'INR' => 'Indian rupee', 'ZAR' => 'South African rand', 'SEK' => 'Swedish krona',
        'NOK' => 'Norwegian krone', 'DKK' => 'Danish krone', 'PLN' => 'Polish zloty', 'CZK' => 'Czech koruna',
        'HUF' => 'Hungarian forint', 'RON' => 'Romanian leu', 'TRY' => 'Turkish lira', 'JPY' => 'Japanese yen',
        'CNY' => 'Chinese yuan', 'KRW' => 'South Korean won', 'MYR' => 'Malaysian ringgit', 'THB' => 'Thai baht',
        'IDR' => 'Indonesian rupiah', 'PHP' => 'Philippine peso', 'ILS' => 'Israeli shekel', 'MXN' => 'Mexican peso',
        'BRL' => 'Brazilian real', 'ISK' => 'Icelandic krona',
    ];

    private static bool $ready = false;

    /** The columns of the update are there ("Update system" was clicked). */
    public static function ready(): bool
    {
        return self::$ready = self::$ready || Schema::hasColumn('sales', 'exchange_rate');
    }

    public static function supports(string $currency): bool
    {
        return array_key_exists(strtoupper($currency), self::CURRENCIES);
    }

    /**
     * How much 1 unit of $from is worth in $to on that day (the last working
     * day's rate at a weekend). Null when no rate can be had.
     */
    public function rate(string $from, string $to, ?CarbonInterface $on = null): ?float
    {
        $from = strtoupper($from);
        $to = strtoupper($to);

        if ($from === $to) {
            return 1.0;
        }

        if (! self::supports($from) || ! self::supports($to)) {
            return null;
        }

        $day = CarbonImmutable::instance($on ?? now())->startOfDay();
        $today = $day->greaterThanOrEqualTo(now()->startOfDay());
        $key = 'fx:'.$from.':'.$to.':'.($today ? 'latest' : $day->toDateString());

        $rate = Cache::get($key);

        if (is_numeric($rate)) {
            return (float) $rate;
        }

        $rate = $this->fetch($from, $to, $today ? 'latest' : $day->toDateString());

        if ($rate !== null) {
            // Today's rate is read again after a few hours; an earlier day's never changes.
            Cache::put($key, $rate, $today ? now()->addHours(6) : now()->addDays(30));
            Cache::forever('fx:last:'.$from.':'.$to, $rate);

            return $rate;
        }

        $last = Cache::get('fx:last:'.$from.':'.$to);

        return is_numeric($last) ? (float) $last : null;
    }

    /** $amount of $from in $to, rounded to cents. */
    public static function convert(float $amount, float $rate): float
    {
        return round($amount * $rate, 2);
    }

    /**
     * A deal typed in the currency the client was charged in, for a brand
     * that counts in its own: the amounts in the brand's currency (what the
     * targets read) and the columns that keep what was charged. The same
     * currency as the brand's changes nothing.
     *
     * @param  float|null  $paidNow  what was paid with the deal, in the charged currency; null for all of it
     * @return array{amount: float, paidNow: float|null, chargedPaid: float|null, columns: array<string, string|float>}
     *
     * @throws InvalidArgumentException when the currency is unknown or no rate can be had
     */
    public function deal(string $brandCurrency, ?string $currency, float $amount, ?float $paidNow, CarbonInterface $on): array
    {
        $currency = strtoupper(trim((string) $currency));

        if ($currency === '' || $currency === strtoupper($brandCurrency) || ! self::ready()) {
            return ['amount' => $amount, 'paidNow' => $paidNow, 'chargedPaid' => null, 'columns' => []];
        }

        if (! self::supports($currency)) {
            throw new InvalidArgumentException('Choose one of the currencies listed.');
        }

        $rate = $this->rate($currency, $brandCurrency, $on);

        if ($rate === null) {
            throw new InvalidArgumentException('The '.$currency.' to '.$brandCurrency.' rate could not be fetched just now. Try again in a minute, or record the sale in '.$brandCurrency.'.');
        }

        $inBrand = self::convert($amount, $rate);
        $paidInBrand = match (true) {
            $paidNow === null => null,
            round($paidNow, 2) >= round($amount, 2) => $inBrand,
            default => min(self::convert(max($paidNow, 0.0), $rate), $inBrand),
        };

        return [
            'amount' => $inBrand,
            'paidNow' => $paidInBrand,
            'chargedPaid' => $paidNow === null ? $amount : min(max(round($paidNow, 2), 0.0), $amount),
            'columns' => ['charged_currency' => $currency, 'charged_amount' => round($amount, 2), 'exchange_rate' => $rate],
        ];
    }

    private function fetch(string $from, string $to, string $day): ?float
    {
        try {
            $response = Http::timeout(6)->acceptJson()->get(self::SOURCE.'/'.$day, ['base' => $from, 'symbols' => $to]);
            $rate = $response->successful() ? $response->json('rates.'.$to) : null;

            return is_numeric($rate) && (float) $rate > 0 ? (float) $rate : null;
        } catch (Throwable) {
            return null;
        }
    }
}
