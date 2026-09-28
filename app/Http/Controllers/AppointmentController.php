<?php

namespace App\Http\Controllers;

use App\Domain\Crm\Actions\ManageAppointment;
use App\Domain\Crm\Services\LeadVisibility;
use App\Models\CrmLead;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class AppointmentController extends Controller
{
    public function index(Request $request, LeadVisibility $visibility): Response
    {
        $org = $request->user()->currentOrganization;
        abort_unless($org !== null, 404);
        $this->authorize('viewCrm', $org);
        $leadIds = $visibility->scope(CrmLead::where('organization_id', $org->id), $org, $request->user())->select('id');
        $appointments = DB::table('brokerage_appointments')->where('organization_id', $org->id)
            ->where(fn ($query) => $query->whereNull('lead_id')->orWhereIn('lead_id', $leadIds))
            ->when(! $request->user()->can('manageCrm', $org), fn ($query) => $query->where('assigned_to', $request->user()->id))
            ->orderBy('starts_at')->paginate(30);

        return Inertia::render('meetings/Index', [
            'appointments' => $appointments,
            'members' => $request->user()->can('manageCrm', $org) ? $org->users()->orderBy('name')->get(['users.id', 'users.name']) : [],
            'canManage' => $request->user()->can('manageCrm', $org),
        ]);
    }

    public function store(Request $request, ManageAppointment $appointments): RedirectResponse
    {
        $org = $request->user()->currentOrganization;
        abort_unless($org !== null, 404);
        $input = $request->validate([
            'type' => ['required', 'in:meeting,viewing'], 'title' => ['required', 'string', 'max:255'],
            'assigned_to' => ['required', 'integer'], 'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'], 'location' => ['nullable', 'string', 'max:255'],
            'lead_id' => ['nullable', 'integer'], 'listing_id' => ['nullable', 'integer'], 'cost_centre_id' => ['nullable', 'integer'],
        ]);
        $appointments->create($org, $request->user(), $input);

        return back();
    }

    public function outcome(Request $request, int $appointment, ManageAppointment $appointments): RedirectResponse
    {
        $org = $request->user()->currentOrganization;
        abort_unless($org !== null, 404);
        $input = $request->validate(['outcome' => ['required', 'string', 'max:3000'], 'stage_id' => ['nullable', 'integer', 'required_with:expected_stage_id'], 'expected_stage_id' => ['nullable', 'integer', 'required_with:stage_id']]);
        $appointments->outcome($org, $request->user(), $appointment, $input);

        return back();
    }
}
