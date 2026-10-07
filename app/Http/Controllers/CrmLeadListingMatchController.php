<?php

namespace App\Http\Controllers;

use App\Domain\Crm\Actions\ManageLeadListingMatches;
use App\Domain\Crm\Actions\ManageLeadMatchSettings;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CrmLeadListingMatchController extends Controller
{
    public function index(Request $request, int $lead, ManageLeadListingMatches $matches): JsonResponse
    {
        return response()->json($matches->index($this->organization($request), $request->user(), $lead, $request->query()));
    }

    public function suggest(Request $request, int $lead, ManageLeadListingMatches $matches): JsonResponse
    {
        return response()->json($matches->suggest($this->organization($request), $request->user(), $lead, $request->query()));
    }

    public function store(Request $request, int $lead, ManageLeadListingMatches $matches): JsonResponse
    {
        return response()->json($matches->create($this->organization($request), $request->user(), $lead, $request->all()), 201);
    }

    public function update(Request $request, int $lead, int $match, ManageLeadListingMatches $matches): JsonResponse
    {
        return response()->json($matches->update($this->organization($request), $request->user(), $lead, $match, $request->all()));
    }

    public function destroy(Request $request, int $lead, int $match, ManageLeadListingMatches $matches): JsonResponse
    {
        return response()->json($matches->delete($this->organization($request), $request->user(), $lead, $match, $request->all()));
    }

    public function configuration(Request $request, ManageLeadMatchSettings $settings): JsonResponse
    {
        return response()->json(['configuration' => $settings->show($this->organization($request), $request->user())]);
    }

    public function updateConfiguration(Request $request, ManageLeadMatchSettings $settings): JsonResponse
    {
        return response()->json(['configuration' => $settings->save($this->organization($request), $request->user(), $request->all())]);
    }

    private function organization(Request $request): Organization
    {
        return $request->user()->currentOrganization ?? abort(404);
    }
}
