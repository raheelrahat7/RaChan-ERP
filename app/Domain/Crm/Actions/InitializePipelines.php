<?php

namespace App\Domain\Crm\Actions;

use App\Domain\Crm\Models\Pipeline;
use App\Models\Organization;
use Illuminate\Support\Facades\DB;

class InitializePipelines
{
    public function handle(Organization $organization): Pipeline
    {
        return DB::transaction(function () use ($organization): Pipeline {
            Organization::whereKey($organization->id)->lockForUpdate()->firstOrFail();
            $existing = Pipeline::where('organization_id', $organization->id)->where('is_default', true)->first();
            if ($existing) {
                return $existing;
            }
            $pipeline = Pipeline::create(['organization_id' => $organization->id, 'name' => 'Sales Pipeline', 'is_default' => true]);
            foreach (['New Leads', 'Assigned Leads', 'Not Qualified', 'Qualified', 'Referral Leads', 'Lead on Hold', 'Won', 'Lost'] as $position => $name) {
                $pipeline->stages()->create(['name' => $name, 'position' => $position + 1, 'is_initial' => $position === 0, 'type' => match ($name) {
                    'Won' => 'won', 'Lost' => 'lost', 'Lead on Hold' => 'on_hold', default => 'normal'
                }]);
            }
            foreach (['No Finance', 'Incorrect Number', 'Already Purchased', 'No Longer Interested', 'Agent Inquiry', 'Went Quiet'] as $position => $name) {
                $pipeline->reasons()->create(['name' => $name, 'position' => $position + 1]);
            }

            return $pipeline;
        });
    }
}
