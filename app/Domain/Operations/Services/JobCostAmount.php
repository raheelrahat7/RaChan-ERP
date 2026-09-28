<?php

namespace App\Domain\Operations\Services;

use Illuminate\Support\Facades\Validator;

class JobCostAmount
{
    public function calculate(string $quantity, string $rate): string
    {
        Validator::make(['quantity' => $quantity, 'unit_rate' => $rate], [
            'quantity' => ['required', 'regex:/^\d{1,6}(\.\d{1,2})?$/', 'numeric', 'min:0.01'],
            'unit_rate' => ['required', 'regex:/^\d{1,6}(\.\d{1,2})?$/', 'numeric', 'min:0'],
        ])->validate();

        // Quantities are hundredths; round the integer product once to the nearest cent.
        return $this->format(intdiv($this->hundredths($quantity) * $this->hundredths($rate) + 50, 100));
    }

    public function hundredths(string $value): int
    {
        $parts = explode('.', $value, 2);

        return ((int) $parts[0] * 100) + (int) str_pad($parts[1] ?? '', 2, '0');
    }

    public function format(int $cents): string
    {
        return intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }
}
