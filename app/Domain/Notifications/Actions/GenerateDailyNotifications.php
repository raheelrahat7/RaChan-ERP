<?php

namespace App\Domain\Notifications\Actions;

use App\Domain\Notifications\Models\NotificationPreference;
use App\Domain\Notifications\Models\OrganizationNotification;
use App\Domain\Operations\Queries\OperationalAlerts;
use App\Models\Organization;
use App\Support\CurrentOperationalAlerts;

class GenerateDailyNotifications
{
    public function __construct(private CurrentOperationalAlerts $alerts) {}

    public function handle(): int
    {
        $created = 0;
        Organization::query()->orderBy('id')->chunkById(100, function ($organizations) use (&$created): void {
            foreach ($organizations as $organization) {
                $preferences = NotificationPreference::where('organization_id', $organization->id)->first();
                if ($preferences && ! $preferences->daily_digest_enabled) {
                    continue;
                }

                $enabledCategories = $preferences->enabled_categories ?? $this->alerts->categories();
                $alerts = array_filter($this->alerts->forOrganization($organization->id), fn (array $alert) => $alert['count'] > 0 && in_array($alert['category'], $enabledCategories, true));
                if ($alerts === []) {
                    continue;
                }

                foreach ($organization->users()->select('users.id')->cursor() as $user) {
                    $operationCounts = app(OperationalAlerts::class)->counts($organization, $user);
                    foreach ($alerts as $alert) {
                        if (array_key_exists($alert['category'], $operationCounts)) {
                            $alert['count'] = $operationCounts[$alert['category']];
                            if ($alert['count'] === 0) {
                                continue;
                            }
                        }
                        $created += OrganizationNotification::query()->insertOrIgnore([
                            'organization_id' => $organization->id,
                            'user_id' => $user->id,
                            'category' => $alert['category'],
                            'event_key' => today()->toDateString().':'.$alert['category'],
                            'title' => $alert['title'],
                            'count' => $alert['count'],
                            'href' => $alert['href'],
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                }
            }
        });

        return $created;
    }
}
