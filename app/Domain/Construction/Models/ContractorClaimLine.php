<?php

namespace App\Domain\Construction\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['organization_id', 'contractor_claim_id', 'construction_boq_item_id', 'quantity', 'unit_rate_cents', 'amount_cents'])]
class ContractorClaimLine extends Model
{
    protected function casts(): array
    {
        return ['quantity' => 'integer', 'unit_rate_cents' => 'integer', 'amount_cents' => 'integer'];
    }
}
