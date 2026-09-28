<?php

namespace App\Domain\Operations\Services;

use App\Domain\Operations\Models\PrivateReportDelivery;
use App\Models\MaintenanceRequest;
use App\Models\Organization;
use App\Models\User;

class PrivateReportAccess
{
    public function authorize(Organization $org, User $actor, PrivateReportDelivery $delivery): void
    {
        abort_unless($delivery->organization_id === $org->id && $delivery->user_id === $actor->id && $delivery->status === 'ready', 404);
        abort_unless($actor->hasVerifiedEmail() && $actor->belongsToOrganization($org) && $actor->can('viewOperations', $org), 403);
        $ids = $delivery->job_ids ?? [];
        $count = app(JobCardAccess::class)->scope(MaintenanceRequest::query(), $org, $actor)->whereIn('id', $ids)->count();
        abort_unless($count === count(array_unique($ids)), 403, 'Access to an included job has changed. Generate a new report.');
    }
}
