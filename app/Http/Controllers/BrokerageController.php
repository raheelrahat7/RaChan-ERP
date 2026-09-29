<?php

namespace App\Http\Controllers;

use App\Domain\Brokerage\Actions\ManageCommissionAllocation;
use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Models\CommissionAllocation;
use App\Models\CommissionPlan;
use App\Models\CommissionTransaction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BrokerageController extends Controller
{
    public function index(Request $request): Response
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization !== null, 404);
        $this->authorize('viewTransactions', $organization);

        return Inertia::render('real-estate/Brokerage', [
            'plans' => CommissionPlan::where('organization_id', $organization->id)->latest()->get(),
            'transactions' => CommissionTransaction::where('organization_id', $organization->id)
                ->with('broker:id,name')->latest()->get(),
            'allocations' => CommissionAllocation::where('organization_id', $organization->id)->latest()->get(),
            'canManage' => $request->user()->can('manageTransactions', $organization),
        ]);
    }

    public function submitAllocation(Request $request, CommissionTransaction $commission, ManageCommissionAllocation $allocations): RedirectResponse
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization !== null, 404);
        $allocations->submit($organization, $request->user(), $commission, $request->only(['net_company', 'agent_payable', 'co_broker', 'referral']));

        return back();
    }

    public function approveAllocation(Request $request, CommissionAllocation $allocation, ManageCommissionAllocation $allocations): RedirectResponse
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization !== null, 404);
        $allocations->approve($organization, $request->user(), $allocation);

        return back();
    }

    public function rejectAllocation(Request $request, CommissionAllocation $allocation, ManageCommissionAllocation $allocations): RedirectResponse
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization !== null, 404);
        $values = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $allocations->reject($organization, $request->user(), $allocation, $values['reason']);

        return back();
    }

    public function storePlan(Request $request, RecordOrganizationAuditLog $audit): RedirectResponse
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization !== null, 404);
        $this->authorize('manageTransactions', $organization);
        $input = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'basis' => ['required', 'in:percentage,fixed'],
            'rate' => ['required', 'numeric', 'min:0'],
        ]);
        $plan = CommissionPlan::create(['organization_id' => $organization->id, ...$input]);
        $audit->handle($organization, $request->user(), 'brokerage.commission_plan.created', $plan);

        return back();
    }
}
