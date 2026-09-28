<?php

namespace App\Domain\Operations\Queries;

use App\Domain\Operations\Models\StockBalance;
use App\Domain\Operations\Models\StockMovement;
use App\Domain\Operations\Models\StockReceiptCost;
use App\Domain\Operations\Services\JobCostAmount;
use App\Models\Organization;
use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;

class StockValuation
{
    public function __construct(private JobCostAmount $amounts) {}

    /** @return array<int, array{value: ?string, currency: ?string, status: string}> */
    public function for(Organization $organization): array
    {
        $costs = StockReceiptCost::where('organization_id', $organization->id)->get()->keyBy('stock_movement_id');
        $result = [];
        $groups = StockMovement::where('organization_id', $organization->id)->orderBy('id')->get()->groupBy('spare_part_id');
        foreach ($groups as $partId => $movements) {
            $quantity = 0;
            $value = 0;
            $known = true;
            $inconsistent = false;
            $currency = null;
            $allocated = [];
            $seen = [];
            $returned = [];
            foreach ($movements as $movement) {
                if (str_starts_with($movement->type, 'transfer_')) {
                    continue;
                }
                $relatedId = $movement->related_movement_id;
                $amount = null;
                if ($movement->type === 'receipt') {
                    $cost = $costs->get($movement->id);
                    $currency ??= $cost?->currency;
                    $amount = $cost?->amount_cents;
                } elseif ($movement->type === 'issue') {
                    $amount = $known && $quantity > 0 ? -$this->share($value, $movement->quantity, $quantity) : null;
                } elseif ($movement->type === 'return') {
                    $original = $seen[$relatedId] ?? null;
                    $originalCost = $allocated[$relatedId] ?? null;
                    if ($original !== null && $originalCost !== null) {
                        $before = $returned[$relatedId] ?? 0;
                        $after = $before + $movement->quantity;
                        $amount = $this->share(-$originalCost, $after, $original->quantity) - $this->share(-$originalCost, $before, $original->quantity);
                        $returned[$relatedId] = $after;
                    }
                } elseif ($movement->type === 'reversal') {
                    $originalCost = $allocated[$relatedId] ?? null;
                    $amount = $originalCost === null ? null : -$originalCost;
                    $original = $seen[$relatedId] ?? null;
                    if ($original?->type === 'return') {
                        $returned[$original->related_movement_id] = ($returned[$original->related_movement_id] ?? 0) - $original->quantity;
                    }
                }
                $quantity += $movement->delta;
                if ($amount === null) {
                    $known = false;
                } else {
                    $value += $amount;
                }
                $allocated[$movement->id] = $amount;
                $seen[$movement->id] = $movement;
                if ($value < 0 || ($known && $quantity === 0 && $value !== 0)) {
                    $inconsistent = true;
                }
                // An exhausted unknown pool has no remaining cost to carry into a future receipt.
                if ($quantity === 0 && ! $known) {
                    $value = 0;
                    $known = true;
                }
            }
            $balances = StockBalance::where('organization_id', $organization->id)->where('spare_part_id', $partId)->orderBy('stock_store_id')->get();
            $total = (int) $balances->sum('quantity');
            $valid = $known && ! $inconsistent && $total === $quantity && $value >= 0;
            $cumulative = 0;
            $distributed = 0;
            foreach ($balances as $balance) {
                $cumulative += $balance->quantity;
                $next = $total > 0 && $valid ? $this->share($value, $cumulative, $total) : 0;
                $result[$balance->id] = ['value' => $valid ? $this->amounts->format($next - $distributed) : null, 'currency' => $currency, 'status' => $valid ? 'valued' : 'missing_or_inconsistent_cost'];
                $distributed = $next;
            }
        }

        return $result;
    }

    private function share(int $value, int $quantity, int $total): int
    {
        return BigInteger::of($value)->multipliedBy($quantity)->dividedBy($total, RoundingMode::HalfUp)->toInt();
    }
}
