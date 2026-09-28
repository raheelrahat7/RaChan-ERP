<?php

namespace App\Domain\Operations\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['organization_id', 'code', 'name', 'unit'])]
class SparePart extends Model {}
