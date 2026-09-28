<?php

namespace App\Domain\RealEstate\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['organization_id', 'name', 'reference', 'email', 'phone'])]
class Developer extends Model
{
    protected $table = 'offplan_developers';
}
