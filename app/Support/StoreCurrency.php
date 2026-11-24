<?php

namespace App\Support;

class StoreCurrency
{
    public static function usdExchangeRate(): ?string
    {
        $rate = config('services.store.usd_exchange_rate');
        if (! is_string($rate) && ! is_int($rate) && ! is_float($rate)) {
            return null;
        }

        $rate = trim((string) $rate);
        if ($rate === '' || ! is_numeric($rate) || (float) $rate <= 0) {
            return null;
        }

        return number_format((float) $rate, 6, '.', '');
    }

    public static function toUsd(string|int|float $penAmount, ?string $rate = null): ?string
    {
        $rate ??= self::usdExchangeRate();
        if (! $rate || ! is_numeric($rate) || (float) $rate <= 0) {
            return null;
        }

        [$whole, $fraction] = array_pad(explode('.', (string) $penAmount, 2), 2, '');
        $penCents = ((int) $whole * 100) + (int) str_pad(substr($fraction, 0, 2), 2, '0');
        $usdCents = (int) round($penCents / (float) $rate);

        return intdiv($usdCents, 100).'.'.str_pad((string) ($usdCents % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function options(): array
    {
        $rate = self::usdExchangeRate();

        return [
            ['code' => 'PEN', 'symbol' => 'S/', 'enabled' => true],
            ['code' => 'USD', 'symbol' => '$', 'enabled' => $rate !== null, 'exchange_rate' => $rate],
        ];
    }
}
