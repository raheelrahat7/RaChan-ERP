<?php

namespace App\Http\Controllers;

use App\Domain\Crm\Actions\ManageLeadFollowUp;
use App\Models\CrmActivity;
use App\Models\CrmLead;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CrmActivityController extends Controller
{
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
}
