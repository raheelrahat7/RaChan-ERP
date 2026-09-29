<?php

namespace App\Http\Controllers;

use App\Domain\Crm\Actions\ManageLeadExportGrants;
use App\Domain\Crm\Queries\ExportLeads;
use App\Domain\Identity\Enums\OrganizationRole;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CrmLeadExportController extends Controller
{
    public function index(Request $request, ExportLeads $exports): Response
    {
        $org = $request->user()->currentOrganization;
        abort_unless($org !== null, 404);

        return Inertia::render('crm/LeadExport', [
            'columns' => $exports->columns($org, $request->user()),
            'canGrant' => $request->user()->hasOrganizationRole($org, OrganizationRole::Owner),
            'members' => $request->user()->hasOrganizationRole($org, OrganizationRole::Owner) ? $org->users()->orderBy('name')->get(['users.id', 'users.name']) : [],
            'grantUserIds' => $request->user()->hasOrganizationRole($org, OrganizationRole::Owner)
                ? DB::table('crm_lead_export_grants')->where('organization_id', $org->id)->pluck('user_id') : [],
        ]);
    }

    public function download(Request $request, ExportLeads $exports): StreamedResponse
    {
        $org = $request->user()->currentOrganization;
        abort_unless($org !== null, 404);
        $data = $request->validate(['columns' => ['required', 'array', 'min:1', 'max:100'], 'columns.*' => ['required', 'string', 'max:100']]);

        return $exports->download($org, $request->user(), $data['columns']);
    }

    public function activities(Request $request, ExportLeads $exports): StreamedResponse
    {
        $org = $request->user()->currentOrganization;
        abort_unless($org !== null, 404);

        return $exports->downloadActivities($org, $request->user());
    }

    public function grant(Request $request, ManageLeadExportGrants $grants): RedirectResponse
    {
        $org = $request->user()->currentOrganization;
        abort_unless($org !== null, 404);
        $data = $request->validate(['user_id' => ['required', 'integer'], 'allowed' => ['required', 'boolean']]);
        $grants->set($org, $request->user(), $data['user_id'], $data['allowed']);

        return back();
    }
}
