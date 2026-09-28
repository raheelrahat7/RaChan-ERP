<?php

namespace App\Domain\Operations\Actions;

use App\Models\Organization;
use App\Models\PreventiveMaintenancePlan;
use Carbon\CarbonImmutable;
use Throwable;

class GenerateDuePreventiveMaintenance
{
    public function __construct(private ManagePreventiveMaintenance $maintenance) {}

    /** @return array{created: int, failed: int} */
    public function handle(): array
    {
        $created = 0;
        $failed = 0;
        // A fixed candidate and work budget bounds each scheduler execution.
        $plans = PreventiveMaintenancePlan::where('auto_generate_enabled', true)->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('automation_last_error')->orWhere('automation_last_run_at', '<', now()->subHour()))
            ->where('next_due_on', '<=', CarbonImmutable::now('UTC')->addDay()->toDateString())
            ->orderBy('next_due_on')->orderBy('id')->limit(100)->get();
        foreach ($plans as $plan) {
            if ($created >= 1000) {
                break;
            }
            try {
                $organization = Organization::findOrFail($plan->organization_id);
                for ($occurrence = 0; $occurrence < 25 && $created < 1000; $occurrence++) {
                    if (! $this->maintenance->generateAutomaticNext($organization, $plan)) {
                        break;
                    }
                    $created++;
                }
            } catch (Throwable $exception) {
                report($exception);
                $failed++;
                $plan->update(['automation_last_run_at' => now(), 'automation_last_error' => 'Automatic generation failed. Review plan links and application logs.']);
            }
        }

        return ['created' => $created, 'failed' => $failed];
    }
}
