<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support;

/** Amounts as people read them: £1,500 or A$2,400.50. */
final class Money
{
    private const SYMBOLS = ['GBP' => '£', 'AUD' => 'A$', 'USD' => '$', 'EUR' => '€', 'CAD' => 'C$', 'NZD' => 'NZ$'];

    public static function symbol(string $currency): string
    {
        return self::SYMBOLS[$currency] ?? $currency.' ';
    }

    public static function format(float|string|null $amount, string $currency): string
    {
        $amount = (float) $amount;
        $whole = abs($amount - round($amount)) < 0.005;

        return ($amount < 0 ? '-' : '').self::symbol($currency).number_format(abs($amount), $whole ? 0 : 2);
    }
}
