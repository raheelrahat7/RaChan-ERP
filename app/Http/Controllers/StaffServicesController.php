<?php

namespace App\Http\Controllers;

use App\Domain\Identity\Actions\ManageStaffServices;
use App\Models\Organization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class StaffServicesController extends Controller
{
    public function index(Request $request, ManageStaffServices $staff): Response
    {
        $org = $this->org($request);
        $manage = $staff->manages($org, $request->user());
        $ids = DB::table('hr_staff')->where('organization_id', $org->id)
            ->when(! $manage, fn ($query) => $query->where('user_id', $request->user()->id))->pluck('id');
        $activity = DB::table('sessions')->select('user_id')->selectRaw('MAX(last_activity) AS last_active')->whereNotNull('user_id')->groupBy('user_id');

        return Inertia::render('hr/Index', [
            'staff' => DB::table('hr_staff as staff')->join('users', 'users.id', '=', 'staff.user_id')->leftJoinSub($activity, 'sessions', 'sessions.user_id', '=', 'staff.user_id')
                ->where('staff.organization_id', $org->id)->whereIn('staff.id', $ids)
                ->orderBy('users.name')->get(['staff.id', 'staff.user_id', 'users.name', 'staff.job_title', 'staff.hired_on', 'staff.dismissed_on', 'staff.status', 'sessions.last_active']),
            'documents' => DB::table('hr_staff_documents')->where('organization_id', $org->id)->whereIn('staff_id', $ids)->orderBy('expires_on')->limit(100)->get(),
            'leaveRequests' => DB::table('hr_leave_requests')->where('organization_id', $org->id)->whereIn('staff_id', $ids)->orderByDesc('id')->limit(100)->get(),
            'members' => $manage ? $org->users()->orderBy('name')->get(['users.id', 'users.name']) : [],
            'canManage' => $manage,
        ]);
    }

    public function show(Request $request, int $staff, ManageStaffServices $manage): Response
    {
        $org = $this->org($request);
        $record = DB::table('hr_staff as staff')->join('users', 'users.id', '=', 'staff.user_id')
            ->where('staff.organization_id', $org->id)->where('staff.id', $staff)
            ->first(['staff.id', 'staff.user_id', 'users.name', 'users.email', 'staff.job_title', 'staff.hired_on', 'staff.dismissed_on', 'staff.dismissal_reason', 'staff.status']);
        abort_unless($record !== null && ($manage->manages($org, $request->user()) || $record->user_id === $request->user()->id), 404);
        $record->last_active = DB::table('sessions')->where('user_id', $record->user_id)->max('last_activity');

        return Inertia::render('hr/Show', [
            'staff' => $record,
            'documents' => DB::table('hr_staff_documents')->where('organization_id', $org->id)->where('staff_id', $record->id)->orderBy('expires_on')->get(),
            'leaveRequests' => DB::table('hr_leave_requests')->where('organization_id', $org->id)->where('staff_id', $record->id)->latest()->get(),
            'canManage' => $manage->manages($org, $request->user()),
            'canDismiss' => $manage->manages($org, $request->user()) && $record->status === 'active' && $record->user_id !== $request->user()->id,
        ]);
    }

    public function staff(Request $request, ManageStaffServices $manage): RedirectResponse
    {
        $org = $this->org($request);
        $manage->staff($org, $request->user(), $request->validate([
            'user_id' => ['required', 'integer'], 'job_title' => ['nullable', 'string', 'max:255'], 'hired_on' => ['nullable', 'date_format:Y-m-d'],
        ]));

        return back();
    }

    public function dismiss(Request $request, int $staff, ManageStaffServices $manage): RedirectResponse
    {
        $org = $this->org($request);
        $input = $request->validate(['dismissed_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'], 'reason' => ['required', 'string', 'max:2000']]);
        $manage->dismiss($org, $request->user(), $staff, $input['dismissed_on'], $input['reason']);

        return back();
    }

    public function document(Request $request, int $staff, ManageStaffServices $manage): RedirectResponse
    {
        $org = $this->org($request);
        $manage->document($org, $request->user(), $staff, $request->validate([
            'type' => ['required', 'in:emirates_id,visa,rera_card,passport,other'], 'expires_on' => ['required', 'date_format:Y-m-d'],
            'reference_suffix' => ['nullable', 'string', 'max:20'], 'notes' => ['nullable', 'string', 'max:2000'],
        ]));

        return back();
    }

    public function leave(Request $request, int $staff, ManageStaffServices $manage): RedirectResponse
    {
        $org = $this->org($request);
        $manage->leave($org, $request->user(), $staff, $request->validate([
            'starts_on' => ['required', 'date_format:Y-m-d'], 'ends_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'type' => ['required', 'in:annual,sick,unpaid,other'], 'reason' => ['nullable', 'string', 'max:2000'],
        ]));

        return back();
    }

    public function decide(Request $request, int $leave, ManageStaffServices $manage): RedirectResponse
    {
        $org = $this->org($request);
        $input = $request->validate(['decision' => ['required', 'in:approve,reject'], 'reason' => ['nullable', 'string', 'max:2000']]);
        $manage->decide($org, $request->user(), $leave, $input['decision'] === 'approve', $input['reason'] ?? null);

        return back();
    }

    private function org(Request $request): Organization
    {
        $org = $request->user()->currentOrganization;
        abort_unless($org !== null && $request->user()->belongsToOrganization($org), 404);

        return $org;
    }
}
