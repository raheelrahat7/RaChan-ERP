<?php

namespace App\Http\Controllers;

use App\Domain\Brokerage\Actions\RecordCommissionClawback;
use App\Domain\Brokerage\Queries\CommissionOverview;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CommissionDataController extends Controller
{
    public function index(Request $request, CommissionOverview $overview): JsonResponse
    {
        $org = $request->user()->currentOrganization;
        abort_unless($org !== null, 404);
        $this->authorize('viewTransactions', $org);
        $filters = $request->validate(['team_id' => ['nullable', 'integer', 'min:1'], 'page' => ['nullable', 'integer', 'min:1']]);
        $canManage = $request->user()->can('manageTransactions', $org);
        $commissions = $overview->query($org, $filters['team_id'] ?? null)->orderByDesc('commission.id')->paginate(30)->withQueryString();
        $commissions->through(fn ($row) => $overview->serialize($row, $canManage));

        return response()->json(['commissions' => $commissions, 'teamSummary' => $overview->teamSummary($org),
            'filters' => $filters, 'permissions' => ['read' => true, 'record_clawback' => $canManage]]);
    }

    public function show(Request $request, int $commission, CommissionOverview $overview): JsonResponse
    {
        $org = $request->user()->currentOrganization;
        abort_unless($org !== null, 404);
        $this->authorize('viewTransactions', $org);
        $row = $overview->query($org)->where('commission.id', $commission)->first();
        abort_unless($row !== null, 404);
        $clawbacks = DB::table('commission_clawbacks')->where('organization_id', $org->id)->where('commission_transaction_id', $commission)
            ->orderByDesc('id')->get()->map(fn ($clawback) => [...(array) $clawback, 'version' => (int) $clawback->version,
                'permissions' => ['read' => true]])->all();

        return response()->json(['commission' => $overview->serialize($row, $request->user()->can('manageTransactions', $org)), 'clawbacks' => $clawbacks]);
    }

    public function storeClawback(Request $request, int $commission, RecordCommissionClawback $clawbacks, CommissionOverview $overview): JsonResponse
    {
        $org = $request->user()->currentOrganization;
        abort_unless($org !== null, 404);
        $record = $clawbacks->handle($org, $request->user(), $commission, $request->all());
        $row = $overview->query($org)->where('commission.id', $commission)->first();
        abort_unless($row !== null, 404);

        $clawback = (array) $record;

        return response()->json(['clawback' => [...$clawback, 'version' => (int) $clawback['version'], 'permissions' => ['read' => true]],
            'commission' => $overview->serialize($row, true)], 201);
    }
}
