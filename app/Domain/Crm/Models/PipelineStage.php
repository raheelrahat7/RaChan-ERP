<?php

namespace App\Domain\Crm\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property positive-int $id */
class PipelineStage extends Model
{
    protected $table = 'crm_pipeline_stages';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'is_initial' => 'boolean', 'allowed_from_stage_ids' => 'array', 'entry_roles' => 'array', 'required_fields' => 'array', 'notify_assignee_on_entry' => 'boolean', 'follow_up_due_days' => 'integer', 'assignment_member_ids' => 'array'];
    }

    /** @return BelongsTo<Pipeline, $this> */
    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(Pipeline::class);
    }
}
