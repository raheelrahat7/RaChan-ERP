<?php

namespace App\Domain\Construction\Queries;

use App\Domain\Construction\Actions\ManageConstruction;
use App\Domain\Construction\Models\BoqItem;
use App\Domain\Construction\Models\BoqProgress;
use App\Domain\Construction\Models\ConstructionProject;
use App\Domain\Construction\Models\ContractorClaim;
use App\Domain\Construction\Models\ContractorClaimLine;
use App\Domain\Identity\Enums\OrganizationRole;
use App\Domain\Operations\Services\JobCostAmount;
use App\Domain\Operations\Services\StockQuantity;
use App\Models\MaintenanceVendor;
use App\Models\Organization;
use App\Models\Property;
use App\Models\User;
use App\Models\VendorBill;
use Illuminate\Support\Facades\Gate;

class ConstructionOverview
{
    public function __construct(private ManageConstruction $construction, private StockQuantity $quantities, private JobCostAmount $money) {}

    /** @return array<string,mixed> */
    public function index(Organization $org, User $actor): array
    {
        Gate::forUser($actor)->authorize('manageOperations', $org);

        return ['properties' => Property::where('organization_id', $org->id)->orderBy('name')->get(['id', 'name']), 'projects' => ConstructionProject::where('organization_id', $org->id)->latest('id')->paginate(20)->withQueryString()->through(fn (ConstructionProject $project): array => [...$project->only(['id', 'reference', 'title', 'status']), 'budget' => $this->money->format($project->budget_cents)])];
    }

    /** @return array<string,mixed> */
    public function project(Organization $org, User $actor, ConstructionProject $project): array
    {
        Gate::forUser($actor)->authorize('manageOperations', $org);
        $project = ConstructionProject::where('organization_id', $org->id)->findOrFail($project->id);
        $items = BoqItem::where('organization_id', $org->id)->where('construction_project_id', $project->id)->orderBy('id')->get();
        $planned = (int) $items->sum('amount_cents');
        $claims = ContractorClaim::where('organization_id', $org->id)->where('construction_project_id', $project->id)->whereIn('status', ['submitted', 'approved', 'billed']);
        $reserved = (int) $claims->sum('amount_cents');

        return ['project' => [...$project->only(['id', 'reference', 'title', 'status']), 'budget' => $this->money->format($project->budget_cents), 'planned' => $this->money->format($planned), 'reserved_claims' => $this->money->format($reserved), 'over_budget' => $planned > $project->budget_cents],
            'vatEnabled' => $org->vat_enabled, 'canApprove' => $actor->hasOrganizationRole($org, OrganizationRole::Owner), 'canFinance' => $actor->can('manageFinance', $org), 'actorId' => $actor->id,
            'vendors' => MaintenanceVendor::where('organization_id', $org->id)->orderBy('name')->get(['id', 'name']),
            'items' => $items->map(fn (BoqItem $item): array => [...$item->only(['id', 'reference', 'description', 'unit']), 'quantity' => $this->quantities->format($item->quantity), 'unit_rate' => $this->money->format($item->unit_rate_cents), 'amount' => $this->money->format($item->amount_cents), 'completed' => $this->quantities->format($this->construction->completed($org, $item)), 'reserved' => $this->quantities->format((int) $this->construction->reserved($org, $item)->sum('quantity'))]),
            'progress' => BoqProgress::where('organization_id', $org->id)->whereIn('construction_boq_item_id', $items->modelKeys())->latest('id')->limit(50)->get()->map(fn (BoqProgress $progress): array => [...$progress->only(['id', 'construction_boq_item_id', 'note', 'voided_at', 'void_reason']), 'quantity' => $this->quantities->format($progress->quantity)]),
            'claims' => ContractorClaim::where('organization_id', $org->id)->where('construction_project_id', $project->id)->latest('id')->paginate(20)->withQueryString()->through(function (ContractorClaim $claim) use ($org): array {
                $bill = $claim->vendor_bill_id === null ? null : VendorBill::where('organization_id', $org->id)->find($claim->vendor_bill_id);

                return [...$claim->only(['id', 'reference', 'status', 'reason', 'requested_by', 'approved_by', 'rejection_reason', 'vendor_bill_id']), 'amount' => $this->money->format($claim->amount_cents), 'claimed_on' => $claim->claimed_on->format('Y-m-d'), 'bill_status' => $bill?->status, 'lines' => ContractorClaimLine::where('organization_id', $org->id)->where('contractor_claim_id', $claim->id)->orderBy('id')->get()->map(fn (ContractorClaimLine $line): array => ['item_id' => $line->construction_boq_item_id, 'quantity' => $this->quantities->format($line->quantity), 'amount' => $this->money->format($line->amount_cents)])];
            })];
    }
}
