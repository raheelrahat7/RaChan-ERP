<?php

namespace App\Domain\Crm\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DealPipeline extends Model
{
    protected $table = 'crm_deal_pipelines';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['lead_field_mapping' => 'array', 'active' => 'boolean', 'is_default' => 'boolean', 'access_configured' => 'boolean'];
    }

    /** @return HasMany<DealStage, $this> */
    public function stages(): HasMany
    {
        return $this->hasMany(DealStage::class, 'pipeline_id')->orderBy('position')->orderBy('id');
    }
}
