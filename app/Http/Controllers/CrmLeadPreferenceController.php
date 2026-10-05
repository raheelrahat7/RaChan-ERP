<?php

namespace App\Http\Controllers;

use App\Domain\Crm\Actions\ManageLeadPreferences;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CrmLeadPreferenceController extends Controller
{
    public function index(Request $request, ManageLeadPreferences $preferences): JsonResponse
    {
        $org = $request->user()->currentOrganization;
        abort_unless($org !== null, 404);

        return response()->json($preferences->for($org, $request->user()));
    }

    public function store(Request $request, ManageLeadPreferences $preferences): JsonResponse
    {
        $org = $request->user()->currentOrganization;
        abort_unless($org !== null, 404);
        $preferences->save($org, $request->user(), $request->all());

        return response()->json($preferences->for($org, $request->user()));
    }

    public function destroy(Request $request, string $preset, ManageLeadPreferences $preferences): JsonResponse
    {
        $org = $request->user()->currentOrganization;
        abort_unless($org !== null, 404);
        $preferences->delete($org, $request->user(), $preset);

        return response()->json($preferences->for($org, $request->user()));
    }
}
