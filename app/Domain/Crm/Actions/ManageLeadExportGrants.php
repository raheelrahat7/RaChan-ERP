<?php

namespace App\Domain\Crm\Actions;

use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Domain\Identity\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ManageLeadExportGrants
{
    public function __construct(private RecordOrganizationAuditLog $audit) {}

    public function set(Organization $org, User $actor, int $userId, bool $allowed): void
    {
        abort_unless($actor->belongsToOrganization($org) && $actor->hasOrganizationRole($org, OrganizationRole::Owner), 403);
        $recipient = $org->users()->whereKey($userId)->first();
        if ($recipient === null) {
            throw ValidationException::withMessages(['user_id' => 'Choose a current organization member.']);
        }
        DB::transaction(function () use ($org, $actor, $recipient, $allowed): void {
            if ($allowed) {
                DB::table('crm_lead_export_grants')->updateOrInsert(
                    ['organization_id' => $org->id, 'user_id' => $recipient->id],
                    ['granted_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]
                );
            } else {
                DB::table('crm_lead_export_grants')->where('organization_id', $org->id)->where('user_id', $recipient->id)->delete();
            }
            $this->audit->handle($org, $actor, $allowed ? 'crm.lead_export.granted' : 'crm.lead_export.revoked', $recipient);
        });
    }
}
