<?php

namespace App\Domain\Accounting\Queries;

use App\Models\AuditLog;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Builder;

class FinanceAuditTrail
{
    /** @return Builder<AuditLog> */
    public function query(Organization $organization, ?string $from, ?string $to, ?string $event, ?int $actorId = null): Builder
    {
        return AuditLog::query()->where('organization_id', $organization->id)
            ->where(fn (Builder $query) => $query->where('event', 'like', 'accounting.%')->orWhere('event', 'like', 'finance.%'))
            ->when($from, fn (Builder $query) => $query->whereDate('created_at', '>=', $from))
            ->when($to, fn (Builder $query) => $query->whereDate('created_at', '<=', $to))
            ->when($event, fn (Builder $query) => $query->where('event', $event))
            ->when($actorId, fn (Builder $query) => $query->where('actor_id', $actorId));
    }
}
