<?php

namespace App\Http\Controllers;

use App\Domain\Crm\Actions\ManageAutomationRule;
use App\Domain\Crm\Actions\ManageCustomFields;
use App\Domain\Crm\Models\AutomationRule;
use App\Domain\Crm\Models\Pipeline;
use App\Domain\Identity\Enums\OrganizationRole;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CrmAutomationController extends Controller
{
    public function index(Request $request, ManageCustomFields $fields): Response
    {
        $org = $request->user()->currentOrganization;
        abort_unless($org !== null, 404);
        abort_unless($request->user()->hasOrganizationRole($org, OrganizationRole::Owner) || $request->user()->hasOrganizationRole($org, OrganizationRole::Administrator), 403);

        return Inertia::render('crm/Automation', [
            'rules' => AutomationRule::where('organization_id', $org->id)->latest()->get(),
            'pipelines' => Pipeline::where('organization_id', $org->id)->with('stages:id,pipeline_id,name,active')->get(['id', 'name', 'active']),
            'conditionFields' => [['key' => 'first_name', 'name' => 'Name'], ['key' => 'last_name', 'name' => 'Last name'], ['key' => 'source', 'name' => 'Source'], ['key' => 'status', 'name' => 'Status'], ['key' => 'city', 'name' => 'City'], ['key' => 'company', 'name' => 'Company'], ['key' => 'assigned_to', 'name' => 'Responsible person'], ...array_map(fn ($field) => ['key' => 'custom:'.$field->key, 'name' => $field->name], $fields->visible($org, $request->user()))],
        ]);
    }

    public function store(Request $request, ManageAutomationRule $rules): RedirectResponse
    {
        $org = $request->user()->currentOrganization;
        abort_unless($org !== null, 404);
        $rules->save($org, $request->user(), $request->all());

        return back();
    }

    public function update(Request $request, AutomationRule $rule, ManageAutomationRule $rules): RedirectResponse
    {
        $org = $request->user()->currentOrganization;
        abort_unless($org !== null, 404);
        $rules->save($org, $request->user(), $request->all(), $rule);

        return back();
    }

    public function destroy(Request $request, AutomationRule $rule, ManageAutomationRule $rules): RedirectResponse
    {
        $org = $request->user()->currentOrganization;
        abort_unless($org !== null, 404);
        $rules->disable($org, $request->user(), $rule);

        return back();
    }
}
