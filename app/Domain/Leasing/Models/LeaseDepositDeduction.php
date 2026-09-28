<?php

namespace App\Domain\Leasing\Models;

use App\Models\Invoice;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['organization_id', 'lease_deposit_settlement_id', 'category', 'description', 'amount', 'evidence', 'invoice_id', 'vat_treatment', 'vat_rate', 'vat_amount', 'offset_payment_id', 'offset_journal_entry_id', 'offset_posted_on', 'offset_approved_by', 'recovery_journal_entry_id', 'recovery_posted_on', 'recovery_approved_by', 'forfeiture_journal_entry_id', 'forfeiture_posted_on', 'forfeiture_approved_by', 'created_by'])]
class LeaseDepositDeduction extends Model
{
    /** @return BelongsTo<LeaseDepositSettlement, $this> */
    public function settlement(): BelongsTo
    {
        return $this->belongsTo(LeaseDepositSettlement::class, 'lease_deposit_settlement_id');
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'vat_rate' => 'decimal:2', 'vat_amount' => 'decimal:2', 'offset_posted_on' => 'date', 'recovery_posted_on' => 'date', 'forfeiture_posted_on' => 'date'];
    }
}
