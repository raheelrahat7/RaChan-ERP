<?php

namespace App\Domain\Finance\Models;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['organization_id', 'year', 'version', 'status', 'currency', 'effective_from', 'supersedes_id', 'reason', 'rejection_reason', 'created_by', 'submitted_by', 'approved_by', 'rejected_by', 'submitted_at', 'approved_at', 'rejected_at'])]
class OperatingBudget extends Model
{
    protected function casts(): array
    {
        return ['year' => 'integer', 'version' => 'integer', 'effective_from' => 'date', 'submitted_at' => 'datetime', 'approved_at' => 'datetime', 'rejected_at' => 'datetime'];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<OperatingBudget, $this> */
    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_id');
    }

    /** @return HasMany<OperatingBudgetLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(OperatingBudgetLine::class);
    }

    /** @return BelongsTo<User, $this> */
    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    /** @return BelongsTo<User, $this> */
    public function rejector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
