<?php

namespace App\Domain\Crm\Models;

use Illuminate\Database\Eloquent\Model;

class MetaImport extends Model
{
    protected $table = 'crm_meta_imports';

    protected $guarded = ['id'];
}
