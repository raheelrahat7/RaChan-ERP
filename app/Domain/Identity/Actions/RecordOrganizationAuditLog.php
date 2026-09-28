<?php

namespace App\Domain\Identity\Actions;

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class RecordOrganizationAuditLog
{
    /** @param array<string, mixed> $properties */
    public function handle(Organization $organization, ?User $actor, string $event, ?Model $subject = null, array $properties = []): void
    {
        AuditLog::create([
            'organization_id' => $organization->id,
            'actor_id' => $actor?->id,
            'event' => $event,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'properties' => $properties,
        ]);
    }
}
