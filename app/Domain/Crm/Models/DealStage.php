<?php

namespace App\Domain\Crm\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property string|null $financial_requirement */
class DealStage extends Model
{
    protected $table = 'crm_deal_stages';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'is_initial' => 'boolean', 'allowed_from_stage_ids' => 'array', 'entry_roles' => 'array', 'required_fields' => 'array'];
    }

    /** @return BelongsTo<DealPipeline, $this> */
    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(DealPipeline::class);
    }
}
