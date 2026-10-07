<?php

namespace App\Http\Controllers;

use App\Domain\Crm\Actions\ManageLeadCommercialRecords;
use App\Domain\Crm\Queries\LeadCommercialOverview;
use App\Domain\Crm\Services\DealAccess;
use App\Domain\Crm\Services\LeadVisibility;
use App\Models\CrmLead;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CrmLeadCommercialController extends Controller
{
    public function __construct(private LeadVisibility $visibility, private ManageLeadCommercialRecords $manage, private LeadCommercialOverview $overview) {}

    public function index(Request $request, CrmLead $lead): JsonResponse
    {
        $org = $this->lead($request, $lead);
        $request->validate(['page' => ['nullable', 'integer', 'min:1']]);

        return response()->json([...$this->overview->records($org, $request->user(), $lead), 'configuration' => $this->manage->settings($org, $request->user())]);
    }

    public function store(Request $request, CrmLead $lead): JsonResponse
    {
        $org = $this->lead($request, $lead);
        $record = $this->manage->save($org, $request->user(), $lead, $request->all());

        return response()->json(['record' => $this->overview->serialize($record, true, app(DealAccess::class)->linkedLeadDeal($org, $request->user(), $lead))], 201);
    }

    public function update(Request $request, CrmLead $lead, int $record): JsonResponse
    {
        $org = $this->lead($request, $lead);
        $saved = $this->manage->save($org, $request->user(), $lead, $request->all(), $record);

        return response()->json(['record' => $this->overview->serialize($saved, true, app(DealAccess::class)->linkedLeadDeal($org, $request->user(), $lead))]);
    }

    public function accounting(Request $request, CrmLead $lead): JsonResponse
    {
        $org = $this->lead($request, $lead);

        return response()->json($this->overview->accounting($org, $request->user(), $lead));
    }

    public function settings(Request $request): JsonResponse
    {
        $org = $this->organization($request);

        return response()->json(['configuration' => $this->manage->settings($org, $request->user())]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $org = $this->organization($request);

        return response()->json(['configuration' => $this->manage->saveSettings($org, $request->user(), $request->all())]);
    }

    private function lead(Request $request, CrmLead $lead): Organization
    {
        $org = $this->organization($request);
        $this->authorize('viewCrm', $org);
        abort_unless($lead->organization_id === $org->id && $this->visibility->canSeeLead($org, $request->user(), $lead->assigned_to), 404);

        return $org;
    }

    private function organization(Request $request): Organization
    {
        return $request->user()->currentOrganization ?? abort(404);
    }
}
