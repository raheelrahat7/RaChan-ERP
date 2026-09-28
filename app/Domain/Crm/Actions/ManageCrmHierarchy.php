<?php

namespace App\Domain\Crm\Actions;

use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Domain\Identity\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ManageCrmHierarchy
{
    public function __construct(private RecordOrganizationAuditLog $audit) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function create(Organization $org, User $actor, string $kind, array $input): void
    {
        DB::transaction(function () use ($org, $actor, $kind, $input): void {
            $table = match ($kind) {
                'department' => 'crm_departments',
                'subdepartment' => 'crm_subdepartments',
                'team' => 'crm_teams',
                default => abort(404),
            };
            $parent = match ($kind) {
                'subdepartment' => ['crm_departments', 'department_id'],
                'team' => ['crm_subdepartments', 'subdepartment_id'],
                default => null,
            };
            if ($parent && ! DB::table($parent[0])->where('organization_id', $org->id)->where('active', true)->where('id', $input[$parent[1]])->exists()) {
                throw ValidationException::withMessages([$parent[1] => 'Select an active parent in this organization.']);
            }
            if ($kind === 'team' && ! DB::table('crm_subdepartments as sub')->join('crm_departments as dept', 'dept.id', '=', 'sub.department_id')->where('sub.organization_id', $org->id)->where('sub.id', $input['subdepartment_id'])->where('dept.active', true)->exists()) {
                throw ValidationException::withMessages(['subdepartment_id' => 'Activate the parent department first.']);
            }
            $attributes = ['organization_id' => $org->id, 'name' => trim($input['name']), 'active' => true, 'created_at' => now(), 'updated_at' => now()];
            if ($parent) {
                $attributes[$parent[1]] = $input[$parent[1]];
            }
            $id = DB::table($table)->insertGetId($attributes);
            $this->audit->handle($org, $actor, 'crm.hierarchy.'.$kind.'_created', null, ['id' => $id, 'name' => $attributes['name']]);
        });
    }

    public function place(Organization $org, User $actor, int $userId, ?int $teamId): void
    {
        DB::transaction(function () use ($org, $actor, $userId, $teamId): void {
            $this->member($org, $userId);
            if ($teamId && ! DB::table('crm_teams as team')->join('crm_subdepartments as sub', 'sub.id', '=', 'team.subdepartment_id')->join('crm_departments as dept', 'dept.id', '=', 'sub.department_id')->where('team.organization_id', $org->id)->where('team.id', $teamId)->where('team.active', true)->where('sub.active', true)->where('dept.active', true)->exists()) {
                throw ValidationException::withMessages(['team_id' => 'Select an active team in this organization.']);
            }
            $before = DB::table('crm_team_memberships')->where('organization_id', $org->id)->where('user_id', $userId)->value('team_id');
            DB::table('crm_team_memberships')->where('organization_id', $org->id)->where('user_id', $userId)->delete();
            if ($teamId) {
                DB::table('crm_team_memberships')->insert(['organization_id' => $org->id, 'user_id' => $userId, 'team_id' => $teamId, 'created_at' => now(), 'updated_at' => now()]);
            }
            $this->audit->handle($org, $actor, 'crm.hierarchy.member_placed', null, ['user_id' => $userId, 'before_team_id' => $before, 'team_id' => $teamId]);
        });
    }

    public function grant(Organization $org, User $actor, int $userId, string $scopeType, int $scopeId): void
    {
        DB::transaction(function () use ($org, $actor, $userId, $scopeType, $scopeId): void {
            $this->member($org, $userId);
            $table = match ($scopeType) {
                'organization' => null,
                'department' => 'crm_departments',
                'subdepartment' => 'crm_subdepartments',
                default => abort(422),
            };
            if ($scopeType === 'organization') {
                $scopeId = 0;
            } elseif (! DB::table($table)->where('organization_id', $org->id)->where('active', true)->where('id', $scopeId)->exists()) {
                throw ValidationException::withMessages(['scope_id' => 'Select an active scope in this organization.']);
            }
            DB::table('crm_visibility_grants')->updateOrInsert(['organization_id' => $org->id, 'user_id' => $userId, 'scope_type' => $scopeType, 'scope_id' => $scopeId], ['updated_at' => now(), 'created_at' => now()]);
            $this->audit->handle($org, $actor, 'crm.visibility.granted', null, ['user_id' => $userId, 'scope_type' => $scopeType, 'scope_id' => $scopeId]);
        });
    }

    public function revoke(Organization $org, User $actor, int $grantId): void
    {
        DB::transaction(function () use ($org, $actor, $grantId): void {
            $grant = DB::table('crm_visibility_grants')->where('organization_id', $org->id)->where('id', $grantId)->first();
            abort_unless($grant !== null, 404);
            DB::table('crm_visibility_grants')->where('id', $grantId)->delete();
            $this->audit->handle($org, $actor, 'crm.visibility.revoked', null, ['user_id' => $grant->user_id, 'scope_type' => $grant->scope_type, 'scope_id' => $grant->scope_id]);
        });
    }

    public function setEditGrant(Organization $org, User $actor, int $userId, bool $allowed): void
    {
        DB::transaction(function () use ($org, $actor, $userId, $allowed): void {
            Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
            $role = $org->users()->where('users.id', $userId)->first()?->pivot->getAttribute('role');
            if (! in_array($role, [OrganizationRole::Member->value, OrganizationRole::Viewer->value], true)) {
                throw ValidationException::withMessages(['user_id' => 'Select a Member or Viewer in this organization.']);
            }
            $query = DB::table('crm_edit_grants')->where('organization_id', $org->id)->where('user_id', $userId);
            $before = $query->exists();
            if ($allowed) {
                DB::table('crm_edit_grants')->updateOrInsert(['organization_id' => $org->id, 'user_id' => $userId], ['created_at' => now(), 'updated_at' => now()]);
            } else {
                $query->delete();
            }
            if ($before !== $allowed) {
                $this->audit->handle($org, $actor, 'crm.permission.edit_changed', null, ['user_id' => $userId, 'before' => $before, 'after' => $allowed]);
            }
        });
    }

    public function setActive(Organization $org, User $actor, string $kind, int $id, bool $active): void
    {
        DB::transaction(function () use ($org, $actor, $kind, $id, $active): void {
            $table = match ($kind) {
                'department' => 'crm_departments',
                'subdepartment' => 'crm_subdepartments',
                'team' => 'crm_teams',
                default => abort(404),
            };
            $item = DB::table($table)->where('organization_id', $org->id)->where('id', $id)->lockForUpdate()->first();
            abort_unless($item !== null, 404);
            if ($active && $kind === 'subdepartment' && ! DB::table('crm_departments')->where('organization_id', $org->id)->where('id', $item->department_id)->where('active', true)->exists()) {
                throw ValidationException::withMessages(['active' => 'Activate the parent department first.']);
            }
            if ($active && $kind === 'team' && ! DB::table('crm_subdepartments as sub')->join('crm_departments as dept', 'dept.id', '=', 'sub.department_id')->where('sub.organization_id', $org->id)->where('sub.id', $item->subdepartment_id)->where('sub.active', true)->where('dept.active', true)->exists()) {
                throw ValidationException::withMessages(['active' => 'Activate the parent subdepartment and department first.']);
            }
            DB::table($table)->where('id', $id)->update(['active' => $active, 'updated_at' => now()]);
            $this->audit->handle($org, $actor, 'crm.hierarchy.'.$kind.'_active_changed', null, ['id' => $id, 'before' => (bool) $item->active, 'after' => $active]);
        });
    }

    private function member(Organization $org, int $userId): void
    {
        if (! $org->users()->where('users.id', $userId)->exists()) {
            throw ValidationException::withMessages(['user_id' => 'Select an organization member.']);
        }
    }
}
