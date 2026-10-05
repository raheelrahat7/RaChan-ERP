<?php

namespace App\Domain\Workflows\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkflowStage extends Model
{
    protected $table = 'reference_workflow_stages';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'is_initial' => 'boolean', 'source_statuses' => 'array', 'entry_roles' => 'array', 'required_fields' => 'array', 'allowed_from_stage_ids' => 'array'];
    }

    /** @return BelongsTo<WorkflowPipeline, $this> */
    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(WorkflowPipeline::class, 'pipeline_id');
    }
}
