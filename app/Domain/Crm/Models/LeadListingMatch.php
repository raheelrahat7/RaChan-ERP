<?php

namespace App\Domain\Crm\Models;

use App\Models\CrmLead;
use App\Models\Listing;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeadListingMatch extends Model
{
    protected $table = 'crm_lead_listing_matches';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['shared' => 'boolean', 'viewing_at' => 'datetime', 'version' => 'integer', 'match_percent_override' => 'integer'];
    }

    /** @return BelongsTo<CrmLead, $this> */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(CrmLead::class, 'lead_id');
    }

    /** @return BelongsTo<Listing, $this> */
    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }
}
