<?php

namespace App\Models;

use App\Domain\Leasing\Models\LeaseCheque;
use App\Domain\Leasing\Models\LeaseEjariRegistration;
use App\Domain\Leasing\Models\LeaseSecurityDeposit;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['organization_id', 'unit_id', 'contact_id', 'tenant_id', 'broker_id', 'reservation_id', 'reference', 'status', 'starts_on', 'ends_on', 'rent_amount', 'currency', 'tenancy_number', 'renewal_due_on', 'last_renewed_on', 'advance_amount', 'version'])]
class Lease extends Model
{
    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return BelongsTo<Unit, $this> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /** @return HasMany<HandoverChecklist, $this> */
    public function handovers(): HasMany
    {
        return $this->hasMany(HandoverChecklist::class);
    }

    /** @return HasOne<LeaseSecurityDeposit, $this> */
    public function securityDeposit(): HasOne
    {
        return $this->hasOne(LeaseSecurityDeposit::class);
    }

    /** @return HasMany<LeaseCheque, $this> */
    public function cheques(): HasMany
    {
        return $this->hasMany(LeaseCheque::class);
    }

    /** @return HasMany<LeaseEjariRegistration, $this> */
    public function ejariRegistrations(): HasMany
    {
        return $this->hasMany(LeaseEjariRegistration::class);
    }

    protected function casts(): array
    {
        return ['starts_on' => 'date:Y-m-d', 'ends_on' => 'date:Y-m-d', 'renewal_due_on' => 'date:Y-m-d', 'last_renewed_on' => 'date:Y-m-d', 'rent_amount' => 'decimal:2', 'advance_amount' => 'decimal:2', 'version' => 'integer'];
    }
}
