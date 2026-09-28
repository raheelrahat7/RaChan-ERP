<?php

namespace App\Domain\Leasing\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['organization_id', 'lease_security_deposit_id', 'status', 'collected_amount', 'deductions_total', 'refund_amount', 'notes', 'created_by', 'submitted_by', 'submitted_at', 'approved_by', 'approved_at', 'refund_journal_entry_id', 'refund_approved_by', 'refund_posted_on'])]
class LeaseDepositSettlement extends Model
{
    /** @return BelongsTo<LeaseSecurityDeposit, $this> */
    public function deposit(): BelongsTo
    {
        return $this->belongsTo(LeaseSecurityDeposit::class, 'lease_security_deposit_id');
    }

    /** @return HasMany<LeaseDepositDeduction, $this> */
    public function deductions(): HasMany
    {
        return $this->hasMany(LeaseDepositDeduction::class);
    }

    protected function casts(): array
    {
        return ['collected_amount' => 'decimal:2', 'deductions_total' => 'decimal:2', 'refund_amount' => 'decimal:2', 'submitted_at' => 'datetime', 'approved_at' => 'datetime', 'refund_posted_on' => 'date'];
    }
}
