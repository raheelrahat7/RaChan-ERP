<?php

namespace App\Domain\Finance\Services;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class EstimatePricing
{
    /** @param array<string, mixed> $input
     * @return array<string, mixed> */
    public function calculate(array $input): array
    {
        $data = Validator::make($input, ['currency' => ['required', 'regex:/^[A-Z]{3}$/'], 'valid_until' => ['nullable', 'date_format:Y-m-d'], 'notes' => ['nullable', 'string', 'max:5000'], 'lines' => ['required', 'array', 'min:1', 'max:100'], 'lines.*' => ['array:description,quantity,unit_price'], 'lines.*.description' => ['required', 'string', 'max:255'], 'lines.*.quantity' => ['required', 'regex:/^\d{1,6}(?:\.\d{1,2})?$/', 'numeric', 'gt:0'], 'lines.*.unit_price' => ['required', 'regex:/^\d{1,8}(?:\.\d{1,2})?$/', 'numeric', 'min:0']])->validate();
        $total = 0;
        foreach ($data['lines'] as &$line) {
            $quantity = explode('.', (string) $line['quantity']);
            $quantityMillis = ((int) $quantity[0] * 100) + (int) str_pad($quantity[1] ?? '', 2, '0');
            $price = explode('.', (string) $line['unit_price']);
            $priceCents = ((int) $price[0] * 100) + (int) str_pad($price[1] ?? '', 2, '0');
            $line['total_cents'] = intdiv($quantityMillis * $priceCents + 50, 100);
            $total += $line['total_cents'];
        }
        unset($line);
        if ($total > 999999999999999) {
            throw ValidationException::withMessages(['lines' => 'The estimate exceeds the supported total.']);
        }
        $data['total_cents'] = $total;
        $data['total'] = intdiv($total, 100).'.'.str_pad((string) ($total % 100), 2, '0', STR_PAD_LEFT);

        return $data;
    }
}
