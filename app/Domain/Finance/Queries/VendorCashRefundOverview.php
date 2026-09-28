<?php

namespace App\Domain\Finance\Queries;

use App\Domain\Finance\Models\VendorCashRefund;
use App\Domain\Finance\Services\VendorRefundCapacity;
use App\Domain\Identity\Enums\OrganizationRole;
use App\Domain\Operations\Services\JobCostAmount;
use App\Models\Organization;
use App\Models\User;
use App\Models\VendorBill;
use Illuminate\Support\Facades\Gate;

class VendorCashRefundOverview
{
    public function __construct(private VendorRefundCapacity $capacity, private JobCostAmount $amounts) {}

    /** @return array<string,mixed> */
    public function for(Organization $org, User $actor): array
    {
        Gate::forUser($actor)->authorize('viewFinance', $org);

        return ['canManage' => $actor->can('manageFinance', $org), 'canApprove' => $actor->hasOrganizationRole($org, OrganizationRole::Owner), 'actorId' => $actor->id,
            'bills' => VendorBill::where('organization_id', $org->id)->where('currency', 'AED')->whereIn('status', ['posted', 'partial', 'paid'])->whereHas('creditNotes', fn ($q) => $q->where('organization_id', $org->id)->where('status', 'posted'))->with(['creditNotes' => fn ($q) => $q->where('organization_id', $org->id)->where('status', 'posted')->select('id', 'vendor_bill_id', 'reference', 'amount')])->orderBy('reference')->get()->map(fn (VendorBill $bill): array => [...$bill->only(['id', 'reference', 'description']), 'available' => $this->amounts->format($this->capacity->available($bill, today()->format('Y-m-d'))), 'credits' => $bill->creditNotes]),
            'refunds' => VendorCashRefund::where('organization_id', $org->id)->latest('id')->paginate(25)->withQueryString()->through(fn (VendorCashRefund $refund): array => $refund->only(['id', 'reference', 'vendor_bill_id', 'vendor_credit_note_id', 'amount', 'reason', 'status', 'requested_by', 'approved_by', 'posted_on', 'reversed_on', 'rejection_reason', 'reversal_reason']))];
    }
}
