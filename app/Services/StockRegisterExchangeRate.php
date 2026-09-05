<?php

namespace App\Services;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class StockRegisterExchangeRate
{
    private const STORAGE_PATH = 'stock-register/exchange-rate.txt';

    public function current(): string
    {
        $configured = (string) config('stock-register.default_exchange_rate', '5.31');
        $value = Storage::disk('local')->exists(self::STORAGE_PATH)
            ? trim((string) Storage::disk('local')->get(self::STORAGE_PATH))
            : $configured;

        if (! preg_match('/^\d+(?:\.\d{1,4})?$/', $value) || BigDecimal::of($value)->isLessThanOrEqualTo(0)) {
            return $configured;
        }

        return $this->trimDecimal(BigDecimal::of($value)->toScale(4, RoundingMode::Down)->__toString());
    }

    public function remember(string $exchangeRate): void
    {
        $normalized = $this->trimDecimal(BigDecimal::of($exchangeRate)->toScale(4, RoundingMode::Down)->__toString());
        if (! Storage::disk('local')->put(self::STORAGE_PATH, $normalized)) {
            throw new RuntimeException('Cursul EUR/RON nu a putut fi memorat.');
        }
    }

    private function trimDecimal(string $value): string
    {
        return rtrim(rtrim($value, '0'), '.');
    }
}
