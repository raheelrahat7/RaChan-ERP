<?php

namespace App\Domain\Finance\Models;

use App\Models\JournalEntry;
use App\Models\Organization;
use App\Models\User;
use App\Models\VendorBill;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['organization_id', 'vendor_bill_id', 'reference', 'amount', 'vat_amount', 'vat_treatment', 'input_vat_recoverable', 'accounting_treatment', 'reason', 'status', 'requested_by', 'approved_by', 'rejected_by', 'reversed_by', 'journal_entry_id', 'reversal_journal_entry_id', 'posted_on', 'reversed_on', 'approved_at', 'rejection_reason', 'reversal_reason'])]
class VendorCreditNote extends Model
{
    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'vat_amount' => 'decimal:2', 'input_vat_recoverable' => 'boolean', 'posted_on' => 'date', 'reversed_on' => 'date', 'approved_at' => 'datetime'];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<VendorBill, $this> */
    public function vendorBill(): BelongsTo
    {
        return $this->belongsTo(VendorBill::class);
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** @return BelongsTo<JournalEntry, $this> */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }
}
