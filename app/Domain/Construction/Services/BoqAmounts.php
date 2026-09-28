<?php

namespace App\Domain\Construction\Services;

use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;

class BoqAmounts
{
    public function total(int $quantity, int $rate): int
    {
        return BigInteger::of($quantity)->multipliedBy($rate)->dividedBy(1000, RoundingMode::HalfUp)->toInt();
    }
}
