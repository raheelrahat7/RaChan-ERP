<?php

namespace App\Domain\Crm\Models;

use Illuminate\Database\Eloquent\Model;

class MetaPage extends Model
{
    protected $table = 'crm_meta_pages';

    protected $guarded = ['id'];

    protected $hidden = ['page_access_token', 'app_secret', 'verify_token'];

    protected function casts(): array
    {
        return ['page_access_token' => 'encrypted', 'app_secret' => 'encrypted', 'verify_token' => 'encrypted', 'active' => 'boolean', 'subscribed_at' => 'datetime'];
    }
}
