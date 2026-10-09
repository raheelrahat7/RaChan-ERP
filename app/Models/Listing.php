<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['organization_id', 'unit_id', 'broker_id', 'owner_id', 'developer_id', 'buyer_contact_id', 'cost_centre_id', 'reference', 'public_token', 'purpose', 'market_segment', 'status', 'price', 'valuation_price', 'mortgage_status', 'noc_status', 'transfer_status', 'currency', 'version', 'workflow_status', 'listing_category', 'unit_category', 'emirate', 'community', 'sub_community', 'trakheesi_permit', 'dld_permit', 'bedroom_type', 'bedrooms', 'bathrooms', 'balconies', 'parking_spaces', 'size_sqft', 'plot_size_sqft', 'furnishing', 'completion_status', 'handover_date', 'grade', 'loading_bay', 'fit_out', 'price_type', 'price_min', 'price_max', 'price_label', 'developer_name', 'portals'])]
class Listing extends Model
{
    protected function casts(): array
    {
        return ['version' => 'integer', 'price' => 'decimal:2', 'valuation_price' => 'decimal:2', 'size_sqft' => 'decimal:2', 'plot_size_sqft' => 'decimal:2', 'price_min' => 'decimal:2', 'price_max' => 'decimal:2', 'handover_date' => 'date:Y-m-d', 'loading_bay' => 'boolean', 'portals' => 'array'];
    }

    /** @return BelongsTo<Unit, $this> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<Owner, $this> */
    public function seller(): BelongsTo
    {
        return $this->belongsTo(Owner::class, 'owner_id');
    }

    /** @return BelongsTo<CrmContact, $this> */
    public function buyerContact(): BelongsTo
    {
        return $this->belongsTo(CrmContact::class, 'buyer_contact_id');
    }
}
