<?php

namespace App\Domain\Crm\Models;

use App\Models\CrmActivity;
use App\Models\CrmLead;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Deal extends Model
{
    protected $table = 'crm_deals';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'gross_commission' => 'decimal:2', 'co_broker_share' => 'decimal:2', 'agent_share' => 'decimal:2', 'expected_close_date' => 'date:Y-m-d', 'stage_changed_at' => 'datetime', 'closed_at' => 'datetime', 'version' => 'integer'];
    }

    /** @return BelongsTo<DealPipeline, $this> */
    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(DealPipeline::class);
    }

    /** @return BelongsTo<DealStage, $this> */
    public function stage(): BelongsTo
    {
        return $this->belongsTo(DealStage::class, 'current_stage_id');
    }

    /** @return BelongsTo<CrmLead, $this> */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(CrmLead::class);
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /** @return HasMany<DealStageHistory, $this> */
    public function history(): HasMany
    {
        return $this->hasMany(DealStageHistory::class)->orderByDesc('id');
    }

    /** @return MorphMany<CrmActivity, $this> */
    public function activities(): MorphMany
    {
        return $this->morphMany(CrmActivity::class, 'subject');
    }
}
