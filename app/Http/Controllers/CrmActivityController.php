<?php

namespace App\Http\Controllers;

use App\Domain\Crm\Actions\ManageLeadFollowUp;
use App\Domain\Crm\Queries\LeadActivityBoard;
use App\Models\CrmActivity;
use App\Models\CrmLead;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CrmActivityController extends Controller
{
    public function index(Request $request, LeadActivityBoard $board): JsonResponse
    {
        $org = $request->user()->currentOrganization;
        abort_unless($org !== null, 404);
        $this->authorize('viewCrm', $org);
        $filters = $request->validate([
            'pipeline_id' => ['nullable', 'integer'], 'stage_id' => ['nullable', 'integer'], 'assignee_id' => ['nullable', 'integer'],
            'q' => ['nullable', 'string', 'max:100'], 'activity_page' => ['nullable', 'integer', 'min:1'],
            'filters' => ['nullable', 'array', 'max:12'], 'filters.*.field' => ['required', 'string', 'max:100'],
            'filters.*.operator' => ['required', 'string', 'max:20'], 'filters.*.value' => ['nullable'], 'filters.*.to' => ['nullable'],
        ]);
        $activities = $board->activities($org, $request->user(), $filters)->with(['subject:id,first_name,last_name', 'creator:id,name'])
            ->latest()->orderByDesc('id')->paginate(50, ['*'], 'activity_page');

        return response()->json(['board' => $board->for($org, $request->user(), $filters), 'activities' => $activities]);
    }

    public function store(Request $request, ManageLeadFollowUp $manage): RedirectResponse
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization !== null, 404);
        $this->authorize('manageCrm', $organization);
        $input = $request->validate([
            'lead_id' => ['required', 'integer'],
            'type' => ['required', 'in:call,email,meeting,task,note'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'due_at' => ['nullable', 'date'],
        ]);
        $lead = CrmLead::query()->where('organization_id', $organization->id)->findOrFail((int) $input['lead_id']);
        $manage->create($organization, $request->user(), $lead, $input);

        return back();
    }

    public function complete(Request $request, CrmActivity $activity, ManageLeadFollowUp $manage): RedirectResponse
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization && $activity->organization_id === $organization->id, 404);
        $this->authorize('manageCrm', $organization);
        $manage->complete($organization, $request->user(), $activity);

        return back();
    }

    public function update(Request $request, CrmActivity $activity, ManageLeadFollowUp $manage): RedirectResponse
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization && $activity->organization_id === $organization->id, 404);
        $this->authorize('manageCrm', $organization);
        $manage->update($organization, $request->user(), $activity, $request->validate([
            'expected_updated_at' => ['required', 'date'],
            'type' => ['sometimes', 'required', 'in:call,email,meeting,task,note'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'due_at' => ['sometimes', 'nullable', 'date'],
        ]));

        return back();
    }
}
