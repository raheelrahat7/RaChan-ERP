<?php

namespace App\Domain\Accounting\Queries;

use App\Domain\Accounting\Models\FixedAsset;
use App\Models\Organization;
use Carbon\CarbonImmutable;

class DepreciationPreview
{
    /**
     * @return array{month: string, rows: list<array{asset_id: int, reference: string, name: string, asset_class: string, depreciable_amount: string, useful_life_months: int|float, active_days: int, days_in_month: int, proposed_charge: string, estimate_effective_month: string|null}>, total: string, status: string}
     */
    public function for(Organization $organization, string $month): array
    {
        $monthStart = CarbonImmutable::createFromFormat('!Y-m', $month)->startOfMonth();
        $monthEnd = $monthStart->endOfMonth()->startOfDay();
        $rows = FixedAsset::where('organization_id', $organization->id)->whereDate('available_for_use_on', '<=', $monthEnd)
            ->where(fn ($query) => $query->whereNull('disposed_on')->orWhereDate('disposed_on', '>=', $monthStart))
            ->withSum(['depreciations as active_depreciations_sum' => fn ($query) => $query->whereNull('reversal_journal_entry_id')], 'amount')
            ->with(['estimateChanges' => fn ($query) => $query->where('effective_month', '<=', $month)->orderBy('effective_month'), 'depreciations' => fn ($query) => $query->whereNull('reversal_journal_entry_id'), 'impairments' => fn ($query) => $query->whereNull('reversal_journal_entry_id')->where('effective_month', '<=', $month)->orderBy('effective_month')])
            ->whereDoesntHave('depreciations', fn ($query) => $query->where('month', $month)->whereNull('reversal_journal_entry_id'))
            ->orderBy('reference')->get()->map(function (FixedAsset $asset) use ($monthStart, $monthEnd): array {
                $change = $asset->estimateChanges->last();
                $scheduleStart = $change ? CarbonImmutable::createFromFormat('!Y-m', $change->effective_month)->startOfMonth() : CarbonImmutable::parse($asset->available_for_use_on)->startOfDay();
                $lifeMonths = $change->remaining_life_months ?? $asset->useful_life_months;
                $lifeEnd = $scheduleStart->addMonths($lifeMonths)->subDay();
                $activeStart = $scheduleStart->max($monthStart);
                $activeEnd = $monthEnd->min($lifeEnd);
                if ($asset->disposed_on) {
                    $activeEnd = $activeEnd->min(CarbonImmutable::parse($asset->disposed_on)->startOfDay());
                }
                $activeDays = $activeEnd->lt($activeStart) ? 0 : (int) round($activeStart->diffInDays($activeEnd)) + 1;
                $latestImpairment = $asset->impairments->last();
                $basisStart = $latestImpairment ? CarbonImmutable::createFromFormat('!Y-m', $latestImpairment->effective_month)->startOfMonth()->max($scheduleStart) : $scheduleStart;
                $basisMonth = $basisStart->format('Y-m');
                $basisLifeMonths = max(1, $basisStart->diffInMonths($lifeEnd->addDay()));
                $accumulatedBefore = $asset->depreciations->where('month', '<', $basisMonth)->sum('amount');
                $scheduleDepreciableCents = max(0, $this->cents($asset->cost) - $this->cents($accumulatedBefore) - $this->cents($asset->impairments->sum('amount')) - $this->cents($change->residual_value ?? $asset->residual_value));
                $schedulePostedCents = $this->cents($asset->depreciations->where('month', '>=', $basisMonth)->sum('amount'));
                $remainingCents = max(0, $scheduleDepreciableCents - $schedulePostedCents);
                $chargeCents = min($remainingCents, (int) round(($scheduleDepreciableCents / $basisLifeMonths) * ($activeDays / $monthStart->daysInMonth)));

                return ['asset_id' => $asset->id, 'reference' => $asset->reference, 'name' => $asset->name, 'asset_class' => $asset->asset_class, 'depreciable_amount' => $this->money($scheduleDepreciableCents), 'useful_life_months' => $basisLifeMonths, 'active_days' => $activeDays, 'days_in_month' => $monthStart->daysInMonth, 'proposed_charge' => $this->money($chargeCents), 'estimate_effective_month' => $change?->effective_month];
            })->filter(fn (array $row) => $row['active_days'] > 0 && $row['proposed_charge'] !== '0.00')->values();

        return ['month' => $month, 'rows' => array_values($rows->all()), 'total' => $this->money($rows->sum(fn (array $row) => $this->cents($row['proposed_charge']))), 'status' => 'preview'];
    }

    private function cents(string|int|float $amount): int
    {
        return (int) round((float) $amount * 100);
    }

    private function money(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
