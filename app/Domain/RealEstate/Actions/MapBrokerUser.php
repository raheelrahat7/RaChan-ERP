<?php

namespace App\Domain\RealEstate\Actions;

use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Domain\Identity\Enums\OrganizationRole;
use App\Models\Broker;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MapBrokerUser
{
    public function __construct(private RecordOrganizationAuditLog $audit) {}

    public function handle(Organization $org, User $actor, int $brokerId, ?int $userId, string $reason): void
    {
        abort_unless($actor->hasOrganizationRole($org, OrganizationRole::Owner)
            || $actor->hasOrganizationRole($org, OrganizationRole::Administrator), 403);
        DB::transaction(function () use ($org, $actor, $brokerId, $userId, $reason): void {
            $broker = Broker::where('organization_id', $org->id)->lockForUpdate()->findOrFail($brokerId);
            if ($userId !== null && (! $org->users()->whereKey($userId)->exists()
                || Broker::where('organization_id', $org->id)->where('user_id', $userId)->whereKeyNot($broker->id)->exists())) {
                throw ValidationException::withMessages(['user_id' => 'Choose an unlinked member of this organization.']);
            }
            $before = $broker->user_id;
            $broker->update(['user_id' => $userId]);
            $this->audit->handle($org, $actor, 'real_estate.broker.user_mapped', $broker,
                ['before_user_id' => $before, 'user_id' => $userId, 'reason' => $reason]);
        });
    }
}
