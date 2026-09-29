<?php

namespace App\Domain\Brokerage\Actions;

use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Domain\Identity\Enums\OrganizationRole;
use App\Models\CommissionAllocation;
use App\Models\CommissionTransaction;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ManageCommissionAllocation
{
    public function __construct(private RecordOrganizationAuditLog $audit) {}

    /** @param array<string, mixed> $input */
    public function submit(Organization $org, User $actor, CommissionTransaction $commission, array $input): CommissionAllocation
    {
        Gate::forUser($actor)->authorize('manageTransactions', $org);
        $rules = ['required', 'string', 'regex:/^\d{1,14}(\.\d{1,2})?$/'];
        $values = Validator::make($input, [
            'net_company' => $rules, 'agent_payable' => $rules,
            'co_broker' => $rules, 'referral' => $rules,
        ])->validate();

        return DB::transaction(function () use ($org, $actor, $commission, $values): CommissionAllocation {
            $commission = CommissionTransaction::where('organization_id', $org->id)->lockForUpdate()->findOrFail($commission->id);
            if ($commission->currency !== 'AED' || $commission->paid_on !== null) {
                throw ValidationException::withMessages(['commission' => 'Choose an unpaid AED commission.']);
            }
            $toCents = static function (string $amount): int {
                [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');

                return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
            };
            $total = array_sum(array_map($toCents, $values));
            if ($total !== $toCents((string) $commission->commission_amount)) {
                throw ValidationException::withMessages(['net_company' => 'The four allocations must equal the commission amount.']);
            }
            $allocation = CommissionAllocation::where('organization_id', $org->id)->where('commission_transaction_id', $commission->id)->lockForUpdate()->first();
            if ($allocation && $allocation->status !== 'rejected') {
                throw ValidationException::withMessages(['commission' => 'This commission already has a submitted or approved allocation.']);
            }
            if ($allocation) {
                $this->audit->handle($org, $actor, 'brokerage.commission_allocation.revised', $allocation, ['previous' => $allocation->only(['net_company', 'agent_payable', 'co_broker', 'referral', 'rejection_reason'])]);
                $allocation->update([...$values, 'status' => 'submitted', 'requested_by' => $actor->id, 'approved_by' => null, 'approved_at' => null, 'rejected_by' => null, 'rejection_reason' => null]);
            } else {
                $allocation = CommissionAllocation::create(['organization_id' => $org->id, 'commission_transaction_id' => $commission->id, ...$values, 'requested_by' => $actor->id]);
            }
            $this->audit->handle($org, $actor, 'brokerage.commission_allocation.submitted', $allocation, $values);

            return $allocation;
        });
    }

    public function approve(Organization $org, User $actor, CommissionAllocation $allocation): void
    {
        $this->owner($org, $actor);
        DB::transaction(function () use ($org, $actor, $allocation): void {
            $allocation = CommissionAllocation::where('organization_id', $org->id)->lockForUpdate()->findOrFail($allocation->id);
            $this->pendingForDifferentOwner($allocation, $actor);
            $commission = CommissionTransaction::where('organization_id', $org->id)->findOrFail($allocation->commission_transaction_id);
            if ($commission->paid_on !== null || $commission->currency !== 'AED') {
                throw ValidationException::withMessages(['commission' => 'The commission is no longer eligible for allocation.']);
            }
            $allocation->update(['status' => 'approved', 'approved_by' => $actor->id, 'approved_at' => now()]);
            $this->audit->handle($org, $actor, 'brokerage.commission_allocation.approved', $allocation);
        });
    }

    public function reject(Organization $org, User $actor, CommissionAllocation $allocation, string $reason): void
    {
        $this->owner($org, $actor);
        Validator::make(['reason' => $reason], ['reason' => ['required', 'string', 'max:2000']])->validate();
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Record a rejection reason.']);
        }
        DB::transaction(function () use ($org, $actor, $allocation, $reason): void {
            $allocation = CommissionAllocation::where('organization_id', $org->id)->lockForUpdate()->findOrFail($allocation->id);
            $this->pendingForDifferentOwner($allocation, $actor);
            $allocation->update(['status' => 'rejected', 'rejected_by' => $actor->id, 'rejection_reason' => $reason]);
            $this->audit->handle($org, $actor, 'brokerage.commission_allocation.rejected', $allocation, ['reason' => $reason]);
        });
    }

    private function owner(Organization $org, User $actor): void
    {
        Gate::forUser($actor)->authorize('viewFinance', $org);
        abort_unless($actor->hasOrganizationRole($org, OrganizationRole::Owner), 403);
    }

    private function pendingForDifferentOwner(CommissionAllocation $allocation, User $actor): void
    {
        if ($allocation->status !== 'submitted' || $allocation->requested_by === $actor->id) {
            throw ValidationException::withMessages(['allocation' => 'A different owner must review a submitted allocation.']);
        }
    }
}
