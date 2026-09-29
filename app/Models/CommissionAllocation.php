<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['organization_id', 'commission_transaction_id', 'net_company', 'agent_payable', 'co_broker', 'referral', 'status', 'requested_by', 'approved_by', 'approved_at', 'rejected_by', 'rejection_reason'])]
class CommissionAllocation extends Model
{
    protected function casts(): array
    {
        return ['net_company' => 'decimal:2', 'agent_payable' => 'decimal:2', 'co_broker' => 'decimal:2', 'referral' => 'decimal:2', 'approved_at' => 'datetime'];
    }
}
