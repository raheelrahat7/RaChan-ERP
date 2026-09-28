<?php

namespace App\Domain\Crm\Actions;

use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ConfigureFollowUpEscalation
{
    public function __construct(private RecordOrganizationAuditLog $audit) {}

    public function handle(Organization $org, User $actor, bool $enabled): void
    {
        DB::transaction(function () use ($org, $actor, $enabled): void {
            $locked = Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
            $before = (bool) $locked->crm_follow_up_escalation_enabled;
            if ($before === $enabled) {
                return;
            }
            $locked->update(['crm_follow_up_escalation_enabled' => $enabled]);
            $this->audit->handle($locked, $actor, 'crm.follow_up.escalation_configured', $locked, ['before' => $before, 'after' => $enabled]);
        });
    }
}
