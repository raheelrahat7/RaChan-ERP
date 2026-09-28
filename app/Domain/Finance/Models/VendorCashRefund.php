<?php

namespace App\Domain\Finance\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['organization_id', 'vendor_bill_id', 'vendor_credit_note_id', 'reference', 'operation_key', 'amount', 'currency', 'reason', 'status', 'requested_by', 'approved_by', 'rejected_by', 'reversed_by', 'journal_entry_id', 'reversal_journal_entry_id', 'posted_on', 'reversed_on', 'approved_at', 'rejection_reason', 'reversal_reason'])]
class VendorCashRefund extends Model
{
    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'posted_on' => 'date', 'reversed_on' => 'date', 'approved_at' => 'datetime'];
    }
}
