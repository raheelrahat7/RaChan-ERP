<?php

namespace App\Http\Controllers;

use App\Domain\Crm\Actions\ManageCrmSettings;
use App\Domain\Crm\Models\Deal;
use App\Domain\Crm\Queries\DealOverview;
use App\Domain\Crm\Services\DealAccess;
use App\Models\Organization;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Page shells for the CRM screens that sit on top of the JSON deal and settings endpoints.
 * Props come from the same query classes the JSON endpoints use, so both stay identical.
 */
class CrmPageController extends Controller
{
    public function deals(Request $request, DealOverview $overview): Response
    {
        $org = $this->organization($request);
        $filters = $request->validate([
            'pipeline_id' => ['nullable', 'integer'], 'stage_id' => ['nullable', 'integer'], 'assigned_to' => ['nullable', 'integer'],
            'category' => ['nullable', 'string', 'max:24'], 'q' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $pipelines = collect($overview->pipelines($org, $request->user()));
        $default = $pipelines->firstWhere('is_default', true) ?? $pipelines->first();
        if (empty($filters['pipeline_id']) && $default) {
            $filters['pipeline_id'] = (int) $default['id'];
        }

        return Inertia::render('crm/Deals', $overview->index($org, $request->user(), $filters));
    }

    public function deal(Request $request, Deal $deal, DealOverview $overview): Response
    {
        $org = $this->organization($request);
        $request->validate(['history_page' => ['nullable', 'integer', 'min:1'], 'timeline_page' => ['nullable', 'integer', 'min:1'], 'activity_page' => ['nullable', 'integer', 'min:1']]);

        return Inertia::render('crm/DealShow', [...$overview->show($org, $request->user(), $deal), 'categoryOptions' => app(ManageCrmSettings::class)->categories($org)]);
    }

    public function dealPipelines(Request $request, DealAccess $access): Response
    {
        $org = $this->organization($request);
        abort_unless($access->administrator($org, $request->user()), 403);

        return Inertia::render('crm/DealPipelines');
    }

    public function permissions(Request $request, DealAccess $access): Response
    {
        $org = $this->organization($request);
        abort_unless($access->administrator($org, $request->user()), 403);

        return Inertia::render('crm/Permissions');
    }

    public function settings(Request $request): Response
    {
        $this->organization($request);

        return Inertia::render('crm/Settings');
    }

    public function referenceSettings(Request $request, string $section): Response
    {
        $this->organization($request);
        abort_unless(in_array($section, ['currency', 'locations', 'num-documents', 'num-invoices'], true), 404);

        return Inertia::render('crm/SettingsReference', ['section' => $section]);
    }

    public function selectionLists(Request $request, DealAccess $access): Response
    {
        $org = $this->organization($request);
        abort_unless($access->administrator($org, $request->user()), 403);

        return Inertia::render('crm/SettingsLists');
    }

    public function catalogSettings(Request $request, string $section): Response
    {
        $this->organization($request);
        abort_unless(in_array($section, ['taxes', 'units', 'detail-templates', 'company-details', 'mailboxes', 'products'], true), 404);

        return Inertia::render('crm/SettingsCatalog', ['section' => $section]);
    }

    public function workflows(Request $request): Response
    {
        abort_unless($request->user()->currentOrganization !== null, 404);

        return Inertia::render('workflows/Index');
    }

    public function workflowPipelines(Request $request): Response
    {
        $org = $request->user()->currentOrganization;
        abort_unless($org !== null, 404);
        $this->authorize('manageSettings', $org);

        return Inertia::render('workflows/Pipelines');
    }

    private function organization(Request $request): Organization
    {
        $org = $request->user()->currentOrganization;
        abort_unless($org !== null, 404);
        $this->authorize('viewCrm', $org);

        return $org;
    }
}
