<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['organization_id', 'name', 'email', 'phone', 'trade'])]
class MaintenanceVendor extends Model {}
