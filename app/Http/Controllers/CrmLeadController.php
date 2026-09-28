<?php

namespace App\Http\Controllers;

use App\Domain\Crm\Actions\ManageLeadFollowUp;
use App\Domain\Crm\Actions\ManageLeadPipeline;
use App\Domain\Crm\Queries\PipelineOverview;
use App\Domain\Crm\Services\LeadVisibility;
use App\Domain\Identity\Enums\OrganizationRole;
use App\Models\CrmActivity;
use App\Models\CrmLead;
use App\Models\Organization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CrmLeadController extends Controller
{
    public function index(Request $request, PipelineOverview $overview, LeadVisibility $visibility): Response
    {
        $organization = $this->currentOrganization($request);
        $this->authorize('viewCrm', $organization);

        return Inertia::render('crm/Leads', [
            ...$overview->leads($organization, $request->validate(['pipeline_id' => ['nullable', 'integer'], 'stage_id' => ['nullable', 'integer'], 'assignee_id' => ['nullable', 'integer']]), $request->user()),
            'canManagePipelines' => $request->user()->can('manageCrmPipelines', $organization),
            'canManageHierarchy' => $request->user()->hasOrganizationRole($organization, OrganizationRole::Owner) || $request->user()->hasOrganizationRole($organization, OrganizationRole::Administrator),
            'assigneeScoped' => $visibility->restricted($organization, $request->user()) && count($visibility->assigneeIds($organization, $request->user())) === 1,
            'limitedVisibility' => $visibility->restricted($organization, $request->user()),
            'members' => $visibility->restricted($organization, $request->user()) ? $organization->users()->whereIn('users.id', $visibility->assigneeIds($organization, $request->user()))->orderBy('name')->get(['users.id', 'users.name'])->map->only(['id', 'name']) : $organization->users()->orderBy('name')->get(['users.id', 'users.name'])->map->only(['id', 'name']),
            'followUps' => CrmActivity::where('organization_id', $organization->id)->whereNotNull('due_at')->whereNull('completed_at')->whereHasMorph('subject', [CrmLead::class], fn ($query) => $visibility->scope($query->where('organization_id', $organization->id)->whereNull('converted_at'), $organization, $request->user()))->with('subject:id,first_name,last_name')->orderBy('due_at')->get()->map(fn ($activity) => [...$activity->only('id', 'type', 'notes', 'due_at'), 'lead' => $activity->subject->only('id', 'first_name', 'last_name'), 'is_overdue' => $activity->due_at->isPast()]),
            'canManageCrm' => $request->user()->can('manageCrm', $organization),
            'activities' => CrmActivity::query()->where('organization_id', $organization->id)->whereHasMorph('subject', [CrmLead::class], fn ($query) => $visibility->scope($query->where('organization_id', $organization->id), $organization, $request->user()))->with(['subject:id,first_name,last_name', 'creator:id,name'])->latest()->take(10)->get()->map(fn (CrmActivity $activity) => [
                ...$activity->only('id', 'type', 'notes', 'due_at'),
                'lead' => $activity->subject ? $activity->subject->only('id', 'first_name', 'last_name') : null,
                'creator' => $activity->creator?->only('id', 'name'),
            ]),
        ]);
    }

    public function store(Request $request, ManageLeadPipeline $manage): RedirectResponse
    {
        $organization = $this->currentOrganization($request);
        $this->authorize('manageCrm', $organization);
        $manage->create($organization, $request->user(), $this->validatedLead($request));

        return back();
    }

    public function convert(Request $request, CrmLead $lead, ManageLeadPipeline $manage): RedirectResponse
    {
        $organization = $this->currentOrganization($request);
        abort_unless($lead->organization_id === $organization->id, 404);
        $this->authorize('manageCrm', $organization);
        $manage->convert($organization, $request->user(), $lead);

        return back();
    }

    public function updateDetails(Request $request, CrmLead $lead, ManageLeadPipeline $manage): RedirectResponse
    {
        $organization = $this->currentOrganization($request);
        abort_unless($lead->organization_id === $organization->id, 404);
        $this->authorize('manageCrm', $organization);
        $data = $this->validatedLead($request);
        unset($data['pipeline_id']);
        $manage->updateDetails($organization, $request->user(), $lead, $data);

        return back();
    }

    public function move(Request $request, CrmLead $lead, ManageLeadPipeline $manage): RedirectResponse
    {
        $organization = $this->currentOrganization($request);
        abort_unless($lead->organization_id === $organization->id, 404);
        $this->authorize('manageCrm', $organization);
        $manage->move($organization, $request->user(), $lead, $request->validate(['stage_id' => ['required', 'integer'], 'expected_stage_id' => ['required', 'integer'], 'lost_reason_id' => ['nullable', 'integer'], 'notes' => ['nullable', 'string', 'max:2000']]));

        return back();
    }

    public function transfer(Request $request, CrmLead $lead, ManageLeadPipeline $manage): RedirectResponse
    {
        $organization = $this->currentOrganization($request);
        abort_unless($lead->organization_id === $organization->id, 404);
        $this->authorize('manageCrm', $organization);
        $manage->transfer($organization, $request->user(), $lead, $request->validate([
            'pipeline_id' => ['required', 'integer'],
            'stage_id' => ['required', 'integer'],
            'expected_pipeline_id' => ['required', 'integer'],
            'expected_stage_id' => ['required', 'integer'],
            'lost_reason_id' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'confirmed' => ['required', 'accepted'],
        ]));

        return back();
    }

    private function currentOrganization(Request $request): Organization
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization !== null, 404);

        return $organization;
    }

    public function assign(Request $request, CrmLead $lead, ManageLeadFollowUp $manage): RedirectResponse
    {
        $organization = $this->currentOrganization($request);
        abort_unless($lead->organization_id === $organization->id, 404);
        $this->authorize('manageCrm', $organization);
        $input = $request->validate(['assigned_to' => ['present', 'nullable', 'integer']]);
        $manage->assign($organization, $request->user(), $lead, isset($input['assigned_to']) ? (int) $input['assigned_to'] : null);

        return back();
    }

    /** @return array<string, string|null> */
    private function validatedLead(Request $request): array
    {
        return $request->validate([
            'pipeline_id' => ['nullable', 'integer'],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'company' => ['nullable', 'string', 'max:255'],
            'source' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'project_name' => ['nullable', 'string', 'max:255'],
            'campaign_name' => ['nullable', 'string', 'max:255'],
            'meta_form_id' => ['nullable', 'string', 'max:100'],
            'meta_form_name' => ['nullable', 'string', 'max:255'],
        ]);
    }
}
