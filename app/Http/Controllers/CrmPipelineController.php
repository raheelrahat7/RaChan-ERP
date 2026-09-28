<?php

namespace App\Http\Controllers;

use App\Domain\Crm\Actions\ManagePipelines;
use App\Domain\Crm\Queries\PipelineOverview;
use App\Domain\Crm\Services\StageEntryRules;
use App\Domain\Identity\Enums\OrganizationRole;
use App\Models\Organization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CrmPipelineController extends Controller
{
    public function index(Request $request, PipelineOverview $overview): Response
    {
        $org = $this->organization($request);

        return Inertia::render('crm/Pipelines', [...$overview->configuration($org), 'members' => $org->users()->orderBy('name')->get(['users.id', 'users.name'])->map->only(['id', 'name'])]);
    }

    public function savePipeline(Request $request, ManagePipelines $manage, ?int $pipeline = null): RedirectResponse
    {
        $org = $this->organization($request);
        $manage->savePipeline($org, $request->user(), $request->validate(['name' => ['required', 'string', 'max:100'], 'description' => ['nullable', 'string', 'max:2000'], 'active' => ['required', 'boolean']]), $pipeline);

        return back();
    }

    public function saveStage(Request $request, int $pipeline, ManagePipelines $manage, ?int $stage = null): RedirectResponse
    {
        $org = $this->organization($request);
        $manage->saveItem($org, $request->user(), $pipeline, 'stage', $request->validate([...$this->itemRules(), 'type' => ['required', Rule::in(['normal', 'on_hold', 'won', 'lost'])], 'color' => ['required', 'regex:/^#[a-fA-F0-9]{6}$/']]), $stage);

        return back();
    }

    public function saveReason(Request $request, int $pipeline, ManagePipelines $manage, ?int $reason = null): RedirectResponse
    {
        $org = $this->organization($request);
        $manage->saveItem($org, $request->user(), $pipeline, 'reason', $request->validate($this->itemRules()), $reason);

        return back();
    }

    public function saveRules(Request $request, int $pipeline, int $stage, ManagePipelines $manage): RedirectResponse
    {
        $org = $this->organization($request);
        $input = $request->validate([
            'restrict_transitions' => ['required', 'boolean'],
            'allowed_from_stage_ids' => ['present', 'array', 'max:1000'],
            'allowed_from_stage_ids.*' => ['integer', 'distinct'],
            'restrict_roles' => ['required', 'boolean'],
            'entry_roles' => ['present', 'array'],
            'entry_roles.*' => ['string', 'distinct', Rule::in(array_column(OrganizationRole::cases(), 'value'))],
            'required_fields' => ['present', 'array'],
            'required_fields.*' => ['string', 'distinct', Rule::in(array_keys(StageEntryRules::FIELDS))],
        ]);
        $manage->saveRules($org, $request->user(), $pipeline, $stage, $input);

        return back();
    }

    public function saveAssigneeNotification(Request $request, int $pipeline, int $stage, ManagePipelines $manage): RedirectResponse
    {
        $org = $this->organization($request);
        $enabled = $request->validate(['enabled' => ['required', 'boolean']])['enabled'];
        $manage->saveAssigneeNotification($org, $request->user(), $pipeline, $stage, (bool) $enabled);

        return back();
    }

    public function saveFollowUpRule(Request $request, int $pipeline, int $stage, ManagePipelines $manage): RedirectResponse
    {
        $org = $this->organization($request);
        $input = $request->validate(['enabled' => ['required', 'boolean'], 'due_days' => ['nullable', 'required_if:enabled,true', 'integer', 'between:0,365']]);
        $manage->saveFollowUpRule($org, $request->user(), $pipeline, $stage, $input['enabled'] ? (int) $input['due_days'] : null);

        return back();
    }

    public function saveAssignmentRule(Request $request, int $pipeline, int $stage, ManagePipelines $manage): RedirectResponse
    {
        $org = $this->organization($request);
        $input = $request->validate(['member_ids' => ['present', 'array', 'max:1000'], 'member_ids.*' => ['integer', 'distinct']]);
        $manage->saveAssignmentRule($org, $request->user(), $pipeline, $stage, $input['member_ids']);

        return back();
    }

    public function destroy(Request $request, string $kind, int $id, ManagePipelines $manage): RedirectResponse
    {
        $org = $this->organization($request);
        abort_unless(in_array($kind, ['pipeline', 'stage', 'reason']), 404);
        $manage->delete($org, $request->user(), $kind, $id);

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function itemRules(): array
    {
        return ['name' => ['required', 'string', 'max:100'], 'description' => ['nullable', 'string', 'max:2000'], 'position' => ['required', 'integer', 'min:1', 'max:10000'], 'active' => ['required', 'boolean']];
    }

    private function organization(Request $request): Organization
    {
        $org = $request->user()->currentOrganization;
        abort_unless($org !== null, 404);
        $this->authorize('manageCrmPipelines', $org);

        return $org;
    }
}
