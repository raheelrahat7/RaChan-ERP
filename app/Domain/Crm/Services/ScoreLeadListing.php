<?php

namespace App\Domain\Crm\Services;

use App\Models\Listing;

class ScoreLeadListing
{
    /** @param array<string, mixed> $requirements
     * @return array{match_percent: int, matched_on: list<string>, evaluated_on: list<string>}
     */
    public function score(array $requirements, Listing $listing): array
    {
        $unit = $listing->unit;
        $property = $unit?->property;
        $checks = [];
        $purpose = match ($requirements['purpose'] ?? null) {
            'buy', 'sell' => 'sale', 'rent', 'lease' => 'rent', default => null,
        };
        if ($purpose !== null) {
            $checks['purpose'] = $listing->purpose === $purpose;
        }
        if (filled($requirements['property_type'] ?? null)) {
            $checks['property_type'] = $unit?->type === $requirements['property_type'];
        }
        $location = trim((string) ($requirements['location'] ?? ''));
        if ($location !== '') {
            $checks['location'] = str_contains(mb_strtolower((string) $property?->name), mb_strtolower($location))
                || str_contains(mb_strtolower((string) $property?->city), mb_strtolower($location));
        } elseif (filled($requirements['emirate'] ?? null)) {
            $checks['emirate'] = str_replace([' ', '-'], '_', mb_strtolower((string) $property?->city)) === $requirements['emirate'];
        }
        if (($requirements['budget_currency'] ?? null) === $listing->currency && (($requirements['budget_min'] ?? null) !== null || ($requirements['budget_max'] ?? null) !== null)) {
            $price = $this->minorUnits((string) $listing->price);
            $checks['budget'] = (($requirements['budget_min'] ?? null) === null || $price >= $this->minorUnits((string) $requirements['budget_min']))
                && (($requirements['budget_max'] ?? null) === null || $price <= $this->minorUnits((string) $requirements['budget_max']));
        }
        if (($requirements['size_unit'] ?? null) === $unit?->area_unit && $unit?->area !== null && (($requirements['size_min'] ?? null) !== null || ($requirements['size_max'] ?? null) !== null)) {
            $area = $this->minorUnits((string) $unit->area);
            $checks['size'] = (($requirements['size_min'] ?? null) === null || $area >= $this->minorUnits((string) $requirements['size_min']))
                && (($requirements['size_max'] ?? null) === null || $area <= $this->minorUnits((string) $requirements['size_max']));
        }

        return ['match_percent' => $checks === [] ? 0 : (int) round(count(array_filter($checks)) * 100 / count($checks)),
            'matched_on' => array_keys(array_filter($checks)), 'evaluated_on' => array_keys($checks)];
    }

    private function minorUnits(string $amount): int
    {
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');

        return (int) ($whole.str_pad($fraction, 2, '0'));
    }
}
