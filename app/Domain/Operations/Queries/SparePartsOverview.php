<?php

namespace App\Domain\Operations\Queries;

use App\Domain\Operations\Models\SparePart;
use App\Domain\Operations\Models\StockBalance;
use App\Domain\Operations\Models\StockMovement;
use App\Domain\Operations\Models\StockStore;
use App\Domain\Operations\Services\StockQuantity;
use App\Models\MaintenanceRequest;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class SparePartsOverview
{
    public function __construct(private StockQuantity $quantities, private StockValuation $valuation) {}

    /** @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function for(Organization $organization, User $actor, array $filters): array
    {
        Gate::forUser($actor)->authorize('manageOperations', $organization);
        $movements = StockMovement::where('organization_id', $organization->id);
        foreach (['spare_part_id', 'stock_store_id', 'maintenance_request_id'] as $field) {
            if (! empty($filters[$field])) {
                $movements->where($field, (int) $filters[$field]);
            }
        }

        $valuation = $this->valuation->for($organization);

        return [
            'parts' => SparePart::where('organization_id', $organization->id)->orderBy('code')->get(),
            'stores' => StockStore::where('organization_id', $organization->id)->orderBy('code')->get(),
            'balances' => StockBalance::where('organization_id', $organization->id)->orderBy('stock_store_id')->orderBy('spare_part_id')->get()->map(fn (StockBalance $balance): array => [...$balance->toArray(), 'available' => $this->quantities->format($balance->quantity), 'valuation' => $valuation[$balance->id] ?? ['value' => null, 'currency' => null, 'status' => 'missing_or_inconsistent_cost']]),
            'movements' => $movements->latest('id')->paginate(50)->withQueryString()->through(fn (StockMovement $movement): array => [...$movement->toArray(), 'quantity_display' => $this->quantities->format($movement->quantity), 'delta_display' => $this->quantities->format($movement->delta)]),
            'jobs' => MaintenanceRequest::where('organization_id', $organization->id)->whereIn('status', ['open', 'in_progress', 'on_hold'])->whereNull('submitted_at')->orderBy('reference')->get(['id', 'reference', 'title']),
            'filters' => $filters,
        ];
    }

    /** @return list<array<string, mixed>> */
    public function job(Organization $organization, MaintenanceRequest $job): array
    {
        return array_values(StockMovement::where('organization_id', $organization->id)->where('maintenance_request_id', $job->id)
            ->with(['part' => fn ($query) => $query->where('organization_id', $organization->id)->select('id', 'code', 'name', 'unit'), 'store' => fn ($query) => $query->where('organization_id', $organization->id)->select('id', 'code', 'name')])->orderBy('id')->get()
            ->map(fn (StockMovement $movement): array => [...$movement->toArray(), 'quantity_display' => $this->quantities->format($movement->quantity), 'delta_display' => $this->quantities->format($movement->delta)])->all());
    }
}
