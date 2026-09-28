<?php

namespace App\Domain\Crm\Queries;

use App\Domain\Crm\Services\LeadVisibility;
use App\Models\CrmActivity;
use App\Models\CrmLead;
use App\Models\Organization;
use App\Models\User;

class FollowUpAlerts
{
    /**
     * @return list<array{category: string, title: string, count: int, href: string}>
     */
    public function forOrganization(int $organizationId, ?User $actor = null): array
    {
        $now = now();
        $organization = $actor ? Organization::findOrFail($organizationId) : null;
        $open = CrmActivity::where('organization_id', $organizationId)->whereNull('completed_at')->whereNotNull('due_at')->whereHasMorph('subject', [CrmLead::class], function ($query) use ($organizationId, $organization, $actor) {
            $query->where('organization_id', $organizationId)->whereNull('converted_at');
            if ($organization !== null) {
                app(LeadVisibility::class)->scope($query, $organization, $actor);
            }
        });

        return [
            ['category' => 'overdue_crm_follow_ups', 'title' => 'Overdue CRM follow-ups', 'count' => (clone $open)->where('due_at', '<', $now)->count(), 'href' => '/crm/leads'],
            ['category' => 'due_crm_follow_ups', 'title' => 'CRM follow-ups due today', 'count' => (clone $open)->whereBetween('due_at', [$now, $now->copy()->endOfDay()])->count(), 'href' => '/crm/leads'],
        ];
    }
}
