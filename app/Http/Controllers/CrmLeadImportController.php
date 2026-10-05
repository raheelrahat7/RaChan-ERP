<?php

namespace App\Http\Controllers;

use App\Domain\Crm\Actions\ImportLeads;
use App\Domain\Crm\Actions\ManageCustomFields;
use App\Domain\Crm\Models\LeadImportBatch;
use App\Domain\Crm\Models\Pipeline;
use App\Domain\Crm\Services\LeadVisibility;
use App\Domain\Identity\Enums\OrganizationRole;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class CrmLeadImportController extends Controller
{
    public function index(Request $request, ImportLeads $imports, ManageCustomFields $fields, LeadVisibility $visibility): Response
    {
        $org = $request->user()->currentOrganization;
        abort_unless($org !== null, 404);
        Gate::authorize('manageCrm', $org);
        $batch = LeadImportBatch::where('organization_id', $org->id)->where('user_id', $request->user()->id)->latest()->first();

        return Inertia::render('crm/LeadImport', [
            'batch' => $batch ? ['id' => $batch->id, 'headers' => $batch->headers, 'preview' => array_slice($batch->rows, 0, 10), 'row_count' => count($batch->rows), 'summary' => $batch->summary, 'errors' => $batch->errors, 'committed_at' => $batch->committed_at, 'source_settings' => $batch->source_settings, 'expires_at' => $batch->expires_at] : null,
            'members' => $org->users()->when($visibility->restricted($org, $request->user()), fn ($query) => $query->whereIn('users.id', $visibility->assigneeIds($org, $request->user())))->orderBy('name')->get(['users.id', 'users.name'])->map->only(['id', 'name']),
            'sourceOptions' => ['encodings' => ['UTF-8', 'Windows-1252'], 'delimiters' => ['comma', 'semicolon', 'tab'], 'nameFormats' => ['first_last', 'last_first']],
            'sampleUrl' => route('crm.leads.import.sample'),
            'targets' => $imports->targets($org, $request->user()),
            'customFields' => array_map(fn ($field) => ['key' => $field->key, 'name' => $field->name, 'type' => $field->type, 'required' => $field->required], $fields->visible($org, $request->user(), true)),
            'fieldTypes' => ManageCustomFields::TYPES,
            'canConfigureFields' => $request->user()->hasOrganizationRole($org, OrganizationRole::Owner) || $request->user()->hasOrganizationRole($org, OrganizationRole::Administrator),
            'pipelines' => Pipeline::where('organization_id', $org->id)->where('active', true)->get(['id', 'name']),
        ]);
    }

    public function preview(Request $request, ImportLeads $imports): RedirectResponse
    {
        $org = $request->user()->currentOrganization;
        abort_unless($org !== null, 404);
        $data = $request->validate(['file' => ['required', 'file']]);
        $imports->preview($org, $request->user(), $data['file'], $request->only('encoding', 'delimiter', 'has_header', 'skip_empty_columns', 'name_format'));

        return back();
    }

    public function commit(Request $request, LeadImportBatch $batch, ImportLeads $imports): RedirectResponse
    {
        $org = $request->user()->currentOrganization;
        abort_unless($org !== null, 404);
        $imports->commit($org, $request->user(), $batch, $request->all());

        return back();
    }

    public function sample(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        $org = $request->user()->currentOrganization;
        abort_unless($org !== null, 404);
        Gate::authorize('manageCrm', $org);

        return response("first_name,last_name,email,phone,city,source\nSample,Prospect,prospect@example.test,+971500000000,Dubai,Referral\n", 200, [
            'Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="lead-import-sample.csv"',
        ]);
    }
}
