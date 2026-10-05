<?php

namespace App\Models;

use App\Domain\Crm\Models\Deal;
use App\Domain\Crm\Models\LeadStageHistory;
use App\Domain\Crm\Models\Pipeline;
use App\Domain\Crm\Models\PipelineStage;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;

#[Fillable(['pipeline_id', 'current_stage_id', 'lost_reason_id', 'stage_changed_at', 'organization_id', 'listing_id', 'assigned_to', 'first_name', 'last_name', 'email', 'phone', 'company', 'city', 'source', 'status', 'notes', 'project_name', 'campaign_name', 'meta_form_id', 'meta_form_name', 'meta_page_id', 'meta_lead_id', 'converted_at', 'converted_contact_id', 'converted_account_id'])]
class CrmLead extends Model
{
    /** @return BelongsTo<Listing, $this> */
    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }

    /** @return BelongsTo<PipelineStage, $this> */
    public function stage(): BelongsTo
    {
        return $this->belongsTo(PipelineStage::class, 'current_stage_id');
    }

    /** @return BelongsTo<Pipeline, $this> */
    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(Pipeline::class);
    }

    /** @return HasMany<LeadStageHistory, $this> */
    public function history(): HasMany
    {
        return $this->hasMany(LeadStageHistory::class, 'lead_id')->orderByDesc('id');
    }

    /** @return HasOne<Deal, $this> */
    public function deal(): HasOne
    {
        return $this->hasOne(Deal::class, 'lead_id');
    }

    protected function casts(): array
    {
        return ['converted_at' => 'datetime', 'stage_changed_at' => 'datetime'];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /** @return MorphMany<CrmActivity, $this> */
    /** @return MorphMany<CrmActivity, $this> */
    public function activities(): MorphMany
    {
        return $this->morphMany(CrmActivity::class, 'subject');
    }
}
