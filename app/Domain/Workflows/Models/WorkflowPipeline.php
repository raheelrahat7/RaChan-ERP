<?php

namespace App\Domain\Workflows\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkflowPipeline extends Model
{
    protected $table = 'reference_workflow_pipelines';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'read_roles' => 'array', 'edit_roles' => 'array', 'field_definitions' => 'array'];
    }

    /** @return HasMany<WorkflowStage, $this> */
    public function stages(): HasMany
    {
        return $this->hasMany(WorkflowStage::class, 'pipeline_id')->orderBy('position')->orderBy('id');
    }
}
