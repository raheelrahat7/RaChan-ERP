<?php

namespace App\Domain\Crm\Actions;

use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ConfigureFollowUpReminders
{
    public function __construct(private RecordOrganizationAuditLog $audit) {}

    public function handle(Organization $org, User $actor, ?int $days): void
    {
        DB::transaction(function () use ($org, $actor, $days): void {
            $locked = Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
            $before = $locked->crm_follow_up_reminder_days;
            $locked->update(['crm_follow_up_reminder_days' => $days]);
            if ($before !== $days) {
                $this->audit->handle($locked, $actor, 'crm.follow_up.reminder_configured', $locked, ['before_days' => $before, 'after_days' => $days]);
            }
        });
    }
}
