<?php

namespace App\Domain\Platform\Queries;

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class OrganizationActivity
{
    /** @param array<string,mixed> $filters
     * @return array<string,mixed> */
    public function for(Organization $org, User $actor, array $filters): array
    {
        Gate::forUser($actor)->authorize('manageMembers', $org);
        $query = AuditLog::where('organization_id', $org->id)->with(['actor' => fn ($q) => $q->select('id', 'name')]);
        if (! empty($filters['module'])) {
            $query->where('event', 'like', $filters['module'].'.%');
        }
        if (! empty($filters['actor_id'])) {
            $query->where('actor_id', (int) $filters['actor_id']);
        }

        return ['filters' => $filters, 'members' => $org->users()->orderBy('name')->get(['users.id', 'users.name']),
            'events' => $query->latest('id')->paginate(50)->withQueryString()->through(fn (AuditLog $log): array => ['id' => $log->id, 'event' => $log->event, 'actor' => $log->actor === null ? 'System' : $log->actor->name, 'subject_type' => $log->subject_type === null ? null : class_basename($log->subject_type), 'subject_id' => $log->subject_id, 'created_at' => $log->created_at->toIso8601String()])];
    }
}
