<?php

namespace App\Domain\Identity\Actions;

use App\Domain\Accounting\Actions\ManageLegacyJournalMapping;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class RemoveOrganizationMember
{
    public function __construct(private ManageLegacyJournalMapping $mappingApprovals, private RecordOrganizationAuditLog $audit) {}

    public function handle(Organization $org, User $actor, User $member): void
    {
        Gate::forUser($actor)->authorize('manageMembers', $org);
        abort_unless($org->users()->whereKey($member)->exists(), 404);
        abort_if($member->id === $actor->id, 422);

        DB::transaction(function () use ($org, $actor, $member): void {
            $this->mappingApprovals->revokeForRemovedMember($org, $actor, $member);
            DB::table('hr_staff')->where('organization_id', $org->id)->where('user_id', $member->id)->where('status', 'active')
                ->update(['status' => 'dismissed', 'dismissed_on' => today()->toDateString(), 'dismissal_reason' => 'Organization membership removed.', 'updated_at' => now()]);
            $org->users()->detach($member);
            DB::table('crm_team_memberships')->where('organization_id', $org->id)->where('user_id', $member->id)->delete();
            DB::table('crm_visibility_grants')->where('organization_id', $org->id)->where('user_id', $member->id)->delete();
            DB::table('crm_edit_grants')->where('organization_id', $org->id)->where('user_id', $member->id)->delete();
            $this->audit->handle($org, $actor, 'organization.member.removed', $member);
        });
    }
}
