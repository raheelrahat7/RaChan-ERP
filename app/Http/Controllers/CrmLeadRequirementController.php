<?php

namespace App\Http\Controllers;

use App\Domain\Crm\Actions\ManageLeadRequirements;
use App\Domain\Crm\Actions\ManageLeadRequirementSettings;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CrmLeadRequirementController extends Controller
{
    public function show(Request $request, int $lead, ManageLeadRequirements $requirements): JsonResponse
    {
        return response()->json($requirements->show($this->organization($request), $request->user(), $lead));
    }

    public function update(Request $request, int $lead, ManageLeadRequirements $requirements): JsonResponse
    {
        return response()->json($requirements->save($this->organization($request), $request->user(), $lead, $request->all()));
    }

    public function configuration(Request $request, ManageLeadRequirementSettings $settings): JsonResponse
    {
        return response()->json(['configuration' => $settings->show($this->organization($request), $request->user())]);
    }

    public function updateConfiguration(Request $request, ManageLeadRequirementSettings $settings): JsonResponse
    {
        return response()->json(['configuration' => $settings->save($this->organization($request), $request->user(), $request->all())]);
    }

    private function organization(Request $request): Organization
    {
        return $request->user()->currentOrganization ?? abort(404);
    }
}
