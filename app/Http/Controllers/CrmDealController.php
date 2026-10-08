<?php

namespace App\Http\Controllers;

use App\Domain\Crm\Actions\ManageCrmSettings;
use App\Domain\Crm\Actions\ManageDealActivities;
use App\Domain\Crm\Actions\ManageDealAutomation;
use App\Domain\Crm\Actions\ManageDealFinancialLinks;
use App\Domain\Crm\Actions\ManageDealPipelines;
use App\Domain\Crm\Actions\ManageDeals;
use App\Domain\Crm\Models\CustomField;
use App\Domain\Crm\Models\Deal;
use App\Domain\Crm\Models\DealAccessRule;
use App\Domain\Crm\Models\DealAutomationExecution;
use App\Domain\Crm\Models\DealAutomationRule;
use App\Domain\Crm\Models\DealPipeline;
use App\Domain\Crm\Queries\DealOverview;
use App\Domain\Crm\Queries\ExportDeals;
use App\Domain\Crm\Services\DealAccess;
use App\Models\CrmLead;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CrmDealController extends Controller
{
    public function index(Request $request, DealOverview $overview): JsonResponse
    {
        return response()->json($overview->index($this->organization($request), $request->user(), $request->validate(['pipeline_id' => ['nullable', 'integer'], 'stage_id' => ['nullable', 'integer'], 'assigned_to' => ['nullable', 'integer'], 'category' => ['nullable', 'string', 'max:24'], 'custom_filters' => ['sometimes', 'array', 'max:20'], 'q' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1']])));
    }

    public function show(Request $request, Deal $deal, DealOverview $overview): JsonResponse
    {
        $request->validate(['history_page' => ['nullable', 'integer', 'min:1'], 'timeline_page' => ['nullable', 'integer', 'min:1'], 'activity_page' => ['nullable', 'integer', 'min:1']]);

        return response()->json($overview->show($this->organization($request), $request->user(), $deal));
    }

    public function store(Request $request, ManageDeals $manage, DealOverview $overview): JsonResponse
    {
        $org = $this->organization($request);
        $deal = $manage->create($org, $request->user(), $request->all());

        return response()->json(['deal' => $overview->serialize($org, $request->user(), $deal)], 201);
    }

    public function fromLead(Request $request, CrmLead $lead, ManageDeals $manage, DealOverview $overview): JsonResponse
    {
        $org = $this->organization($request);
        $deal = $manage->create($org, $request->user(), $request->all(), $lead);

        return response()->json(['deal' => $overview->serialize($org, $request->user(), $deal)]);
    }

    public function update(Request $request, Deal $deal, ManageDeals $manage, DealOverview $overview): JsonResponse
    {
        $org = $this->organization($request);

        return response()->json(['deal' => $overview->serialize($org, $request->user(), $manage->update($org, $request->user(), $deal, $request->all()))]);
    }

    public function move(Request $request, Deal $deal, ManageDeals $manage, DealOverview $overview): JsonResponse
    {
        $org = $this->organization($request);

        return response()->json(['deal' => $overview->serialize($org, $request->user(), $manage->move($org, $request->user(), $deal, $request->all()))]);
    }

    public function transfer(Request $request, Deal $deal, ManageDeals $manage, DealOverview $overview): JsonResponse
    {
        $org = $this->organization($request);

        return response()->json(['deal' => $overview->serialize($org, $request->user(), $manage->move($org, $request->user(), $deal, $request->all(), true))]);
    }

    public function configuration(Request $request, DealAccess $access): JsonResponse
    {
        $org = $this->organization($request);
        abort_unless($access->administrator($org, $request->user()), 403);

        return response()->json(['pipelines' => DealPipeline::where('organization_id', $org->id)->with('stages')->orderBy('position')->get(), 'accessRules' => DealAccessRule::where('organization_id', $org->id)->get(), 'actions' => DealAccess::ACTIONS, 'scopes' => DealAccess::SCOPES, 'financialRequirements' => ManageDealFinancialLinks::REQUIREMENTS, 'categories' => app(ManageCrmSettings::class)->activeCategories($org), 'categoryOptions' => app(ManageCrmSettings::class)->categories($org), 'dealStatusOptions' => app(ManageCrmSettings::class)->dealOptions($org, 'deal_statuses'), 'dealScenarioOptions' => app(ManageCrmSettings::class)->dealOptions($org, 'deal_scenarios'), 'requiredFields' => [...ManageDeals::REQUIRED_FIELDS, ...CustomField::where('organization_id', $org->id)->where('entity', 'deal')->where('active', true)->pluck('key')->map(fn ($key) => 'custom:'.$key)->all()]]);
    }

    public function pipeline(Request $request, ManageDealPipelines $manage, ?int $pipeline = null): JsonResponse
    {
        return response()->json(['pipeline' => $manage->save($this->organization($request), $request->user(), $request->all(), $pipeline)]);
    }

    public function stage(Request $request, int $pipeline, ManageDealPipelines $manage, ?int $stage = null): JsonResponse
    {
        return response()->json(['stage' => $manage->stage($this->organization($request), $request->user(), $pipeline, $request->all(), $stage)]);
    }

    public function access(Request $request, int $pipeline, ManageDealPipelines $manage): JsonResponse
    {
        return response()->json(['rule' => $manage->access($this->organization($request), $request->user(), $pipeline, $request->all())]);
    }

    public function destroy(Request $request, string $kind, int $id, ManageDealPipelines $manage): JsonResponse
    {
        abort_unless(in_array($kind, ['pipeline', 'stage', 'access'], true), 404);
        $manage->delete($this->organization($request), $request->user(), $kind, $id);

        return response()->json(['deleted' => true]);
    }

    public function activity(Request $request, Deal $deal, ManageDealActivities $manage, ?int $activity = null): JsonResponse
    {
        $saved = $manage->save($this->organization($request), $request->user(), $deal, $request->all(), $activity);

        return response()->json(['activity' => $saved, 'version' => $deal->fresh()->version]);
    }

    public function financialLink(Request $request, Deal $deal, ManageDealFinancialLinks $links): JsonResponse
    {
        $org = $this->organization($request);
        $links->save($org, $request->user(), $deal, $request->all());

        return response()->json(['financialRecords' => $links->summary($org, $request->user(), $deal->fresh()), 'version' => $deal->fresh()->version]);
    }

    public function export(Request $request, ExportDeals $exports): StreamedResponse
    {
        return $exports->download($this->organization($request), $request->user(), $request->validate(['pipeline_id' => ['nullable', 'integer'], 'stage_id' => ['nullable', 'integer'], 'assigned_to' => ['nullable', 'integer'], 'category' => ['nullable', 'string', 'max:24'], 'custom_filters' => ['sometimes', 'array', 'max:20'], 'q' => ['nullable', 'string', 'max:100']]));
    }

    public function automation(Request $request): JsonResponse
    {
        $org = $this->organization($request);
        abort_unless(app(DealAccess::class)->administrator($org, $request->user()), 403);

        return response()->json(['rules' => DealAutomationRule::where('organization_id', $org->id)->get(), 'actions' => ManageDealAutomation::ACTIONS, 'executions' => DealAutomationExecution::where('organization_id', $org->id)->latest('id')->paginate(100)]);
    }

    public function saveAutomation(Request $request, ManageDealAutomation $manage, ?int $rule = null): JsonResponse
    {
        return response()->json(['rule' => $manage->save($this->organization($request), $request->user(), $request->all(), $rule)]);
    }

    private function organization(Request $request): Organization
    {
        $org = $request->user()->currentOrganization;
        abort_unless($org !== null, 404);
        $this->authorize('viewCrm', $org);

        return $org;
    }
}
