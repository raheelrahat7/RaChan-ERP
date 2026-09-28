<?php

namespace Tests\Unit;

use App\Domain\Operations\Services\StockQuantity;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class StockQuantityTest extends TestCase
{
    public function test_small_fractional_receipts_and_issues_remain_exact(): void
    {
        $quantities = new StockQuantity;
        $received = $quantities->parse('0.100') + $quantities->parse('0.2');
        $issued = $quantities->parse('0.299');
        $this->assertSame('0.300', $quantities->format($received));
        $this->assertSame('0.001', $quantities->format($received - $issued));
        $this->assertSame('-0.299', $quantities->format(-$issued));
        $this->assertSame('0.000', $quantities->format(0));
    }

    public function test_large_quantity_totals_remain_exact_without_float_rounding(): void
    {
        $quantities = new StockQuantity;
        $maximum = $quantities->parse('999999.999');
        $this->assertSame(999999999, $maximum);
        $this->assertSame('1999999.998', $quantities->format($maximum + $maximum));
    }

    public function test_invalid_movement_quantities_are_rejected_without_rounding_or_coercion(): void
    {
        foreach (['0', '0.000', '-1', '0.0001', '1.1234', '1000000', '1e3', '1,000', '1\n', ' 1', '1 ', '', '.5', '1.'] as $value) {
            try {
                (new StockQuantity)->parse($value, 'issued_quantity');
                $this->fail('Invalid movement quantity accepted.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('issued_quantity', $exception->errors());
            }
        }
    }
}
