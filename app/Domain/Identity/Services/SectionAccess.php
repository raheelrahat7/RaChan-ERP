<?php

namespace App\Domain\Identity\Services;

use App\Domain\Identity\Enums\OrganizationPermission;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SectionAccess
{
    public function enabled(Organization $org, User $actor, OrganizationPermission $permission, string $role): bool
    {
        if (in_array($role, ['owner', 'administrator'], true)) {
            return true;
        }
        $rules = DB::table('organization_section_access')->where('organization_id', $org->id)->where('permission', $permission->value)->where('enabled', false)->get();
        if ($rules->isEmpty()) {
            return true;
        }
        $placements = DB::table('crm_team_memberships as member')->join('crm_teams as team', 'team.id', '=', 'member.team_id')->join('crm_subdepartments as sub', 'sub.id', '=', 'team.subdepartment_id')->join('crm_departments as department', 'department.id', '=', 'sub.department_id')
            ->where('member.organization_id', $org->id)->where('member.user_id', $actor->id)->where('team.organization_id', $org->id)->where('sub.organization_id', $org->id)->where('department.organization_id', $org->id)
            ->where('team.active', true)->where('sub.active', true)->where('department.active', true)->get(['team.id as team_id', 'sub.id as subdepartment_id', 'sub.department_id']);

        return ! $rules->contains(fn ($rule) => match ($rule->principal_type) {
            'role' => $rule->principal_id === $role,
            'user' => $rule->principal_id === (string) $actor->id,
            'team' => $placements->contains('team_id', (int) $rule->principal_id),
            'subdepartment' => $placements->contains('subdepartment_id', (int) $rule->principal_id),
            'department' => $placements->contains('department_id', (int) $rule->principal_id),
            default => false,
        });
    }
}
