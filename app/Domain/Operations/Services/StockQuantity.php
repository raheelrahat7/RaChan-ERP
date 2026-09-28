<?php

namespace App\Domain\Operations\Services;

use Illuminate\Validation\ValidationException;

class StockQuantity
{
    public function parse(string $value, string $field = 'quantity'): int
    {
        if (! preg_match('/^\d{1,6}(?:\.\d{1,3})?$/D', $value)) {
            throw ValidationException::withMessages([$field => 'Enter a positive quantity up to 999,999.999 with at most three decimal places.']);
        }
        $parts = explode('.', $value, 2);
        $quantity = ((int) $parts[0] * 1000) + (int) str_pad($parts[1] ?? '', 3, '0');
        if ($quantity === 0) {
            throw ValidationException::withMessages([$field => 'Quantity must be greater than zero.']);
        }

        return $quantity;
    }

    public function format(int $thousandths): string
    {
        $magnitude = abs($thousandths);

        return ($thousandths < 0 ? '-' : '').intdiv($magnitude, 1000).'.'.str_pad((string) ($magnitude % 1000), 3, '0', STR_PAD_LEFT);
    }
}
