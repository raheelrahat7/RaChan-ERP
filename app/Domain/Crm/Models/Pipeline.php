<?php

namespace App\Domain\Crm\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** @property positive-int $id */
class Pipeline extends Model
{
    protected $table = 'crm_pipelines';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'is_default' => 'boolean'];
    }

    /** @return HasMany<PipelineStage, $this> */
    public function stages(): HasMany
    {
        return $this->hasMany(PipelineStage::class, 'pipeline_id')->orderBy('position')->orderBy('id');
    }

    /** @return HasMany<LostReason, $this> */
    public function reasons(): HasMany
    {
        return $this->hasMany(LostReason::class, 'pipeline_id')->orderBy('position')->orderBy('id');
    }
}
