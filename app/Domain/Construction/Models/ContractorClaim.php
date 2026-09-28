<?php

namespace App\Domain\Construction\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['organization_id', 'construction_project_id', 'vendor_id', 'reference', 'operation_key', 'claimed_on', 'amount_cents', 'reason', 'status', 'requested_by', 'approved_by', 'rejected_by', 'approved_at', 'rejection_reason', 'vendor_bill_id'])]
class ContractorClaim extends Model
{
    protected function casts(): array
    {
        return ['amount_cents' => 'integer', 'claimed_on' => 'date', 'approved_at' => 'datetime'];
    }
}
