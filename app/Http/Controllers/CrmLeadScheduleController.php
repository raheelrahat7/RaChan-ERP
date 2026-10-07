<?php

namespace App\Http\Controllers;

use App\Domain\Crm\Actions\ManageAppointment;
use App\Domain\Crm\Services\LeadVisibility;
use App\Domain\Platform\Actions\ManageWorkTask;
use App\Models\CrmLead;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CrmLeadScheduleController extends Controller
{
    public function __construct(private LeadVisibility $visibility, private ManageWorkTask $tasks, private ManageAppointment $meetings) {}

    public function tasks(Request $request, CrmLead $lead): JsonResponse
    {
        $org = $this->lead($request, $lead);
        $manage = $this->tasks->manages($org, $request->user());
        $rows = DB::table('work_tasks as t')->join('users as u', 'u.id', '=', 't.assigned_to')
            ->where('t.organization_id', $org->id)->where('t.related_type', 'lead')->where('t.related_id', $lead->id)
            ->when(! $manage, fn ($query) => $query->where('t.assigned_to', $request->user()->id))
            ->select('t.*', 'u.name as assignee_name')->orderByRaw('t.due_at IS NULL')->orderBy('t.due_at')->orderBy('t.id')
            ->paginate(30)->through(fn ($row) => $this->taskPayload($row, $request, $manage));

        return response()->json(['tasks' => $rows, 'permissions' => ['view' => true, 'create' => $manage && $lead->converted_at === null]]);
    }

    public function createTask(Request $request, CrmLead $lead): JsonResponse
    {
        $org = $this->lead($request, $lead);
        if ($lead->converted_at !== null) {
            throw ValidationException::withMessages(['lead' => 'Converted leads cannot receive new tasks.']);
        }
        $input = $request->validate([
            'title' => ['required', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:3000'],
            'priority' => ['sometimes', 'in:low,normal,high,urgent'], 'assigned_to' => ['required', 'integer'], 'due_at' => ['nullable', 'date'],
        ]);
        $id = $this->tasks->create($org, $request->user(), $input + ['related_type' => 'lead', 'related_id' => $lead->id]);

        return response()->json(['task' => $this->taskPayload($this->taskRow($org, $lead, $id), $request, true)], 201);
    }

    public function updateTask(Request $request, CrmLead $lead, int $task): JsonResponse
    {
        $org = $this->lead($request, $lead);
        $this->taskRow($org, $lead, $task);
        $input = $request->validate([
            'expected_version' => ['required', 'integer', 'min:1'], 'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:3000'], 'priority' => ['sometimes', 'in:low,normal,high,urgent'],
            'assigned_to' => ['sometimes', 'integer'], 'due_at' => ['sometimes', 'nullable', 'date'],
        ]);
        $this->tasks->update($org, $request->user(), $task, $input + ['related_type' => 'lead', 'related_id' => $lead->id]);

        return response()->json(['task' => $this->taskPayload($this->taskRow($org, $lead, $task), $request, true)]);
    }

    public function completeTask(Request $request, CrmLead $lead, int $task): JsonResponse
    {
        $org = $this->lead($request, $lead);
        $this->taskRow($org, $lead, $task);
        $input = $request->validate(['expected_version' => ['required', 'integer', 'min:1']]);
        $this->tasks->complete($org, $request->user(), $task, $input['expected_version']);

        return response()->json(['task' => $this->taskPayload($this->taskRow($org, $lead, $task), $request, $this->tasks->manages($org, $request->user()))]);
    }

    public function meetings(Request $request, CrmLead $lead): JsonResponse
    {
        $org = $this->lead($request, $lead);
        $manage = $request->user()->can('manageCrm', $org);
        $rows = DB::table('brokerage_appointments as a')->join('users as u', 'u.id', '=', 'a.assigned_to')
            ->where('a.organization_id', $org->id)->where('a.lead_id', $lead->id)
            ->when(! $manage, fn ($query) => $query->where('a.assigned_to', $request->user()->id))
            ->select('a.*', 'u.name as assignee_name')->orderBy('a.starts_at')->orderBy('a.id')
            ->paginate(30)->through(fn ($row) => $this->meetingPayload($row, $request, $manage));

        return response()->json(['meetings' => $rows, 'permissions' => ['view' => true, 'create' => $manage && $lead->converted_at === null]]);
    }

    public function createMeeting(Request $request, CrmLead $lead): JsonResponse
    {
        $org = $this->lead($request, $lead);
        if ($lead->converted_at !== null) {
            throw ValidationException::withMessages(['lead' => 'Converted leads cannot receive new meetings.']);
        }
        $input = $request->validate($this->meetingRules(true));
        $id = $this->meetings->create($org, $request->user(), $input + ['lead_id' => $lead->id]);

        return response()->json(['meeting' => $this->meetingPayload($this->meetingRow($org, $lead, $id), $request, true)], 201);
    }

    public function updateMeeting(Request $request, CrmLead $lead, int $meeting): JsonResponse
    {
        $org = $this->lead($request, $lead);
        $this->meetingRow($org, $lead, $meeting);
        $input = $request->validate($this->meetingRules(false));
        $this->meetings->update($org, $request->user(), $meeting, $input);

        return response()->json(['meeting' => $this->meetingPayload($this->meetingRow($org, $lead, $meeting), $request, true)]);
    }

    public function completeMeeting(Request $request, CrmLead $lead, int $meeting): JsonResponse
    {
        $org = $this->lead($request, $lead);
        $this->meetingRow($org, $lead, $meeting);
        $input = $request->validate([
            'expected_version' => ['required', 'integer', 'min:1'], 'outcome' => ['required', 'string', 'max:3000'],
            'stage_id' => ['nullable', 'integer', 'required_with:expected_stage_id'],
            'expected_stage_id' => ['nullable', 'integer', 'required_with:stage_id'],
        ]);
        $this->meetings->outcome($org, $request->user(), $meeting, $input);

        return response()->json(['meeting' => $this->meetingPayload($this->meetingRow($org, $lead, $meeting), $request, $request->user()->can('manageCrm', $org))]);
    }

    public function cancelMeeting(Request $request, CrmLead $lead, int $meeting): JsonResponse
    {
        $org = $this->lead($request, $lead);
        $this->meetingRow($org, $lead, $meeting);
        $input = $request->validate(['expected_version' => ['required', 'integer', 'min:1']]);
        $this->meetings->cancel($org, $request->user(), $meeting, $input['expected_version']);

        return response()->json(['meeting' => $this->meetingPayload($this->meetingRow($org, $lead, $meeting), $request, true)]);
    }

    private function lead(Request $request, CrmLead $lead): Organization
    {
        $org = $request->user()->currentOrganization;
        abort_unless($org && $lead->organization_id === $org->id, 404);
        $this->authorize('viewCrm', $org);
        abort_unless($this->visibility->canSeeLead($org, $request->user(), $lead->assigned_to), 404);

        return $org;
    }

    private function taskRow(Organization $org, CrmLead $lead, int $id): object
    {
        return DB::table('work_tasks as t')->join('users as u', 'u.id', '=', 't.assigned_to')
            ->where('t.organization_id', $org->id)->where('t.related_type', 'lead')->where('t.related_id', $lead->id)
            ->where('t.id', $id)->select('t.*', 'u.name as assignee_name')->firstOrFail();
    }

    private function meetingRow(Organization $org, CrmLead $lead, int $id): object
    {
        return DB::table('brokerage_appointments as a')->join('users as u', 'u.id', '=', 'a.assigned_to')
            ->where('a.organization_id', $org->id)->where('a.lead_id', $lead->id)->where('a.id', $id)
            ->select('a.*', 'u.name as assignee_name')->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function taskPayload(object $row, Request $request, bool $manage): array
    {
        $row = (array) $row;

        return [
            'id' => $row['id'], 'title' => $row['title'], 'description' => $row['description'], 'priority' => $row['priority'],
            'status' => $row['status'], 'assigned_to' => $row['assigned_to'], 'assignee_name' => $row['assignee_name'],
            'due_at' => $row['due_at'], 'completed_at' => $row['completed_at'], 'created_at' => $row['created_at'],
            'version' => $row['version'], 'permissions' => ['view' => true, 'edit' => $manage && $row['status'] === 'open', 'complete' => $row['status'] === 'open' && ($manage || $row['assigned_to'] === $request->user()->id)],
        ];
    }

    /** @return array<string, mixed> */
    private function meetingPayload(object $row, Request $request, bool $manage): array
    {
        $row = (array) $row;

        return [
            'id' => $row['id'], 'type' => $row['type'], 'title' => $row['title'], 'assigned_to' => $row['assigned_to'],
            'assignee_name' => $row['assignee_name'], 'listing_id' => $row['listing_id'], 'cost_centre_id' => $row['cost_centre_id'],
            'starts_at' => $row['starts_at'], 'ends_at' => $row['ends_at'], 'location' => $row['location'], 'status' => $row['status'],
            'outcome' => $row['outcome'], 'completed_at' => $row['completed_at'], 'created_at' => $row['created_at'],
            'version' => $row['version'], 'permissions' => ['view' => true, 'edit' => $manage && $row['status'] === 'scheduled', 'complete' => $row['status'] === 'scheduled' && ($manage || $row['assigned_to'] === $request->user()->id), 'cancel' => $manage && $row['status'] === 'scheduled'],
        ];
    }

    /** @return array<string, array<int, string>> */
    private function meetingRules(bool $create): array
    {
        return [
            'expected_version' => $create ? ['prohibited'] : ['required', 'integer', 'min:1'],
            'type' => [$create ? 'required' : 'sometimes', 'in:meeting,viewing'],
            'title' => [$create ? 'required' : 'sometimes', 'string', 'max:255'],
            'assigned_to' => [$create ? 'required' : 'sometimes', 'integer'],
            'starts_at' => [$create ? 'required' : 'sometimes', 'date'],
            'ends_at' => [$create ? 'required' : 'sometimes', 'date', 'after:starts_at'],
            'location' => ['sometimes', 'nullable', 'string', 'max:255'],
            'listing_id' => ['sometimes', 'nullable', 'integer'],
            'cost_centre_id' => ['sometimes', 'nullable', 'integer'],
        ];
    }
}
