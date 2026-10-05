<?php

namespace App\Domain\Workflows\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkflowRecord extends Model
{
    protected $table = 'reference_workflow_records';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['details' => 'array', 'closed_at' => 'datetime', 'stage_changed_at' => 'datetime'];
    }

    /** @return BelongsTo<WorkflowPipeline, $this> */
    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(WorkflowPipeline::class, 'pipeline_id');
    }

    /** @return BelongsTo<WorkflowStage, $this> */
    public function stage(): BelongsTo
    {
        return $this->belongsTo(WorkflowStage::class, 'stage_id');
    }
}
