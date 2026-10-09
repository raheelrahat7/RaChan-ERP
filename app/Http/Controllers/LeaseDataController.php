<?php

namespace App\Http\Controllers;

use App\Domain\Leasing\Actions\ManageLeaseDetails;
use App\Domain\Leasing\Queries\LeaseOverview;
use App\Models\Lease;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LeaseDataController extends Controller
{
    public function index(Request $request, LeaseOverview $overview): JsonResponse
    {
        $org = $request->user()->currentOrganization;
        abort_unless($org !== null, 404);
        $this->authorize('viewTransactions', $org);
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'tab' => ['nullable', 'in:draft,active,renewal_due,renewed,moved_out'], 'page' => ['nullable', 'integer', 'min:1']]);
        $canManage = $request->user()->can('manageTransactions', $org);
        $leases = $overview->query($org, $filters)->with(['tenant', 'unit', 'securityDeposit', 'cheques', 'ejariRegistrations', 'handovers'])->paginate(50)->withQueryString();
        $leases->through(fn (Lease $lease) => $overview->serialize($lease, $canManage));

        return response()->json(['leases' => $leases, 'tabCounts' => $overview->tabCounts($org, $filters), 'filters' => $filters, 'permissions' => ['read' => true, 'create' => $canManage]]);
    }

    public function show(Request $request, Lease $lease, LeaseOverview $overview): JsonResponse
    {
        $org = $request->user()->currentOrganization;
        abort_unless($org !== null && $lease->organization_id === $org->id, 404);
        $this->authorize('viewTransactions', $org);

        return response()->json(['lease' => $overview->serialize($lease, $request->user()->can('manageTransactions', $org))]);
    }

    public function update(Request $request, Lease $lease, ManageLeaseDetails $details, LeaseOverview $overview): JsonResponse
    {
        $org = $request->user()->currentOrganization;
        abort_unless($org !== null && $lease->organization_id === $org->id, 404);
        $saved = $details->update($org, $request->user(), $lease, $request->all());

        return response()->json(['lease' => $overview->serialize($saved, true)]);
    }
}
