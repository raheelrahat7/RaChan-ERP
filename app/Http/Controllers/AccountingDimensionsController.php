<?php

namespace App\Http\Controllers;

use App\Domain\Accounting\Actions\ManageAccountingDimensions;
use App\Domain\Identity\Enums\OrganizationRole;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class AccountingDimensionsController extends Controller
{
    public function index(Request $request): Response
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization !== null, 404);
        $this->authorize('viewFinance', $organization);

        return Inertia::render('finance/AccountingDimensions', [
            'companies' => DB::table('accounting_companies')->where('organization_id', $organization->id)->orderBy('name')->get(['id', 'code', 'name', 'active']),
            'branches' => DB::table('accounting_branches')->where('organization_id', $organization->id)->orderBy('name')->get(['id', 'company_id', 'code', 'name', 'active']),
            'costCentres' => DB::table('accounting_cost_centres')->where('organization_id', $organization->id)->orderBy('name')->get(['id', 'branch_id', 'code', 'name', 'active']),
            'canManage' => $request->user()->hasOrganizationRole($organization, OrganizationRole::Owner)
                || $request->user()->hasOrganizationRole($organization, OrganizationRole::Administrator),
        ]);
    }

    public function store(Request $request, string $level, ManageAccountingDimensions $dimensions): RedirectResponse
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization !== null, 404);
        abort_unless($request->user()->hasOrganizationRole($organization, OrganizationRole::Owner)
            || $request->user()->hasOrganizationRole($organization, OrganizationRole::Administrator), 403);
        abort_unless(in_array($level, ['company', 'branch', 'cost_centre'], true), 404);
        $input = $request->validate([
            'code' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9_-]+$/'],
            'name' => ['required', 'string', 'max:255'],
            'active' => ['sometimes', 'boolean'],
            'company_id' => [$level === 'branch' ? 'required' : 'prohibited', 'integer'],
            'branch_id' => [$level === 'cost_centre' ? 'required' : 'prohibited', 'integer'],
        ]);
        $dimensions->create($organization, $request->user(), $level, $input);

        return back();
    }
}
