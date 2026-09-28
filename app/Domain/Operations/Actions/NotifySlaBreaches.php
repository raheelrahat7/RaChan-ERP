<?php

namespace App\Domain\Operations\Actions;

use App\Domain\Notifications\Models\OrganizationNotification;
use App\Domain\Operations\Models\JobSlaCycle;
use App\Domain\Operations\Services\JobSlaClock;
use App\Models\MaintenanceRequest;
use App\Models\Organization;
use Illuminate\Support\Facades\DB;

class NotifySlaBreaches
{
    public function __construct(private JobSlaClock $clock) {}

    public function handle(): int
    {
        $created = 0;
        JobSlaCycle::whereNull('closed_at')->orderBy('id')->chunkById(100, function ($cycles) use (&$created): void {
            foreach ($cycles as $candidate) {
                $created += DB::transaction(function () use ($candidate): int {
                    $job = MaintenanceRequest::where('organization_id', $candidate->organization_id)->lockForUpdate()->find($candidate->maintenance_request_id);
                    if ($job === null) {
                        return 0;
                    }
                    $cycle = JobSlaCycle::where('organization_id', $job->organization_id)->findOrFail($candidate->id);
                    if ($cycle->closed_at !== null) {
                        return 0;
                    }
                    $organization = Organization::findOrFail($job->organization_id);
                    $summary = $this->clock->summary($cycle);
                    $count = 0;
                    foreach ($organization->users()->cursor() as $recipient) {
                        if (! $recipient->can('manageOperations', $organization)) {
                            continue;
                        }
                        foreach (['response', 'resolution'] as $kind) {
                            if (! $summary[$kind.'_breached']) {
                                continue;
                            }
                            $count += OrganizationNotification::query()->insertOrIgnore(['organization_id' => $organization->id, 'user_id' => $recipient->id, 'category' => 'operations_sla', 'event_key' => 'sla:'.$cycle->id.':'.$kind, 'title' => $job->reference.' '.$kind.' SLA breached', 'count' => 1, 'href' => '/maintenance/'.$job->id.'/job-card', 'created_at' => now(), 'updated_at' => now()]);
                        }
                    }

                    return $count;
                });
            }
        });

        return $created;
    }
}
