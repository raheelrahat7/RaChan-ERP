<?php

namespace App\Domain\Fleet\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['organization_id', 'property_id', 'fixed_asset_id', 'reference', 'plate', 'vin', 'make', 'model', 'year', 'odometer', 'status'])]
class FleetVehicle extends Model
{
    protected function casts(): array
    {
        return ['year' => 'integer', 'odometer' => 'integer'];
    }
}
