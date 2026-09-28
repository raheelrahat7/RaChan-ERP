<?php

namespace App\Http\Controllers;

use App\Domain\Crm\Actions\ManageCrmHierarchy;
use App\Domain\Identity\Enums\OrganizationRole;
use App\Models\Organization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CrmHierarchyController extends Controller
{
    public function index(Request $request): Response
    {
        $org = $this->organization($request);
        $this->authorizeAdmin($request, $org);

        return Inertia::render('crm/Hierarchy', [
            'departments' => DB::table('crm_departments')->where('organization_id', $org->id)->orderBy('name')->get(['id', 'name', 'active']),
            'subdepartments' => DB::table('crm_subdepartments')->where('organization_id', $org->id)->orderBy('name')->get(['id', 'department_id', 'name', 'active']),
            'teams' => DB::table('crm_teams')->where('organization_id', $org->id)->orderBy('name')->get(['id', 'subdepartment_id', 'name', 'active']),
            'members' => $org->users()->orderBy('name')->get(['users.id', 'users.name'])->map(fn ($user) => ['id' => $user->id, 'name' => $user->name, 'role' => $user->pivot->getAttribute('role')]),
            'placements' => DB::table('crm_team_memberships')->where('organization_id', $org->id)->get(['user_id', 'team_id']),
            'grants' => DB::table('crm_visibility_grants')->where('organization_id', $org->id)->orderBy('user_id')->get(['id', 'user_id', 'scope_type', 'scope_id']),
            'editGrantUserIds' => DB::table('crm_edit_grants')->where('organization_id', $org->id)->pluck('user_id'),
        ]);
    }

    public function create(Request $request, string $kind, ManageCrmHierarchy $manage): RedirectResponse
    {
        $org = $this->organization($request);
        $this->authorizeAdmin($request, $org);
        abort_unless(in_array($kind, ['department', 'subdepartment', 'team'], true), 404);
        $input = $request->validate(['name' => ['required', 'string', 'max:100'], 'department_id' => ['required_if:kind,subdepartment', 'nullable', 'integer'], 'subdepartment_id' => ['nullable', 'integer']]);
        if ($kind === 'subdepartment' && empty($input['department_id'])) {
            abort(422);
        }
        if ($kind === 'team' && empty($input['subdepartment_id'])) {
            abort(422);
        }
        $manage->create($org, $request->user(), $kind, $input);

        return back();
    }

    public function place(Request $request, ManageCrmHierarchy $manage): RedirectResponse
    {
        $org = $this->organization($request);
        $this->authorizeAdmin($request, $org);
        $input = $request->validate(['user_id' => ['required', 'integer'], 'team_id' => ['nullable', 'integer']]);
        $manage->place($org, $request->user(), $input['user_id'], $input['team_id'] ?? null);

        return back();
    }

    public function grant(Request $request, ManageCrmHierarchy $manage): RedirectResponse
    {
        $org = $this->organization($request);
        $this->authorizeAdmin($request, $org);
        $input = $request->validate(['user_id' => ['required', 'integer'], 'scope_type' => ['required', Rule::in(['organization', 'department', 'subdepartment'])], 'scope_id' => ['nullable', 'integer']]);
        $manage->grant($org, $request->user(), $input['user_id'], $input['scope_type'], (int) ($input['scope_id'] ?? 0));

        return back();
    }

    public function revoke(Request $request, int $grant, ManageCrmHierarchy $manage): RedirectResponse
    {
        $org = $this->organization($request);
        $this->authorizeAdmin($request, $org);
        $manage->revoke($org, $request->user(), $grant);

        return back();
    }

    public function setEditGrant(Request $request, ManageCrmHierarchy $manage): RedirectResponse
    {
        $org = $this->organization($request);
        $this->authorizeAdmin($request, $org);
        $input = $request->validate(['user_id' => ['required', 'integer'], 'allowed' => ['required', 'boolean']]);
        $manage->setEditGrant($org, $request->user(), (int) $input['user_id'], (bool) $input['allowed']);

        return back();
    }

    public function setActive(Request $request, string $kind, int $id, ManageCrmHierarchy $manage): RedirectResponse
    {
        $org = $this->organization($request);
        $this->authorizeAdmin($request, $org);
        $active = $request->validate(['active' => ['required', 'boolean']])['active'];
        $manage->setActive($org, $request->user(), $kind, $id, (bool) $active);

        return back();
    }

    private function organization(Request $request): Organization
    {
        $org = $request->user()->currentOrganization;
        abort_unless($org !== null, 404);

        return $org;
    }

    private function authorizeAdmin(Request $request, Organization $org): void
    {
        abort_unless($request->user()->hasOrganizationRole($org, OrganizationRole::Owner) || $request->user()->hasOrganizationRole($org, OrganizationRole::Administrator), 403);
    }
}
