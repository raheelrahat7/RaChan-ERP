<?php

namespace App\Http\Controllers;

use App\Domain\Crm\Actions\ManageCustomFields;
use App\Domain\Crm\Models\CustomField;
use App\Domain\Identity\Enums\OrganizationRole;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CrmCustomFieldController extends Controller
{
    public function index(Request $request): Response
    {
        $org = $request->user()->currentOrganization;
        abort_unless($org !== null, 404);
        abort_unless($request->user()->hasOrganizationRole($org, OrganizationRole::Owner) || $request->user()->hasOrganizationRole($org, OrganizationRole::Administrator), 403);

        return Inertia::render('crm/CustomFields', [
            'fields' => CustomField::where('organization_id', $org->id)->orderBy('sort_order')->orderBy('id')->get(),
            'types' => ManageCustomFields::TYPES,
        ]);
    }

    public function store(Request $request, ManageCustomFields $fields): RedirectResponse
    {
        $org = $request->user()->currentOrganization;
        abort_unless($org !== null, 404);
        $fields->save($org, $request->user(), $request->all());

        return back();
    }

    public function update(Request $request, CustomField $field, ManageCustomFields $fields): RedirectResponse
    {
        $org = $request->user()->currentOrganization;
        abort_unless($org !== null, 404);
        $fields->save($org, $request->user(), $request->all(), $field);

        return back();
    }

    public function destroy(Request $request, CustomField $field, ManageCustomFields $fields): RedirectResponse
    {
        $org = $request->user()->currentOrganization;
        abort_unless($org !== null, 404);
        $fields->archive($org, $request->user(), $field);

        return back();
    }
}
