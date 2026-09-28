<?php

namespace App\Domain\Crm\Observers;

use App\Domain\Crm\Actions\InitializePipelines;
use App\Domain\Crm\Models\Pipeline;
use App\Models\CrmLead;
use App\Models\Organization;
use Illuminate\Validation\ValidationException;

class LeadPipelineObserver
{
    public function creating(CrmLead $lead): void
    {
        $org = Organization::findOrFail($lead->organization_id);
        $default = app(InitializePipelines::class)->handle($org);
        $pipeline = Pipeline::where('organization_id', $org->id)->where('active', true)->find($lead->pipeline_id ?? $default->id);
        if (! $pipeline) {
            throw ValidationException::withMessages(['pipeline_id' => 'Select an active pipeline in your organization.']);
        }
        $stage = $pipeline->stages()->where('is_initial', true)->where('active', true)->where('type', 'normal')->firstOrFail();
        $lead->pipeline_id = $pipeline->id;
        $lead->current_stage_id = $stage->id;
        $lead->lost_reason_id = null;
        $lead->stage_changed_at = now();
    }
}
