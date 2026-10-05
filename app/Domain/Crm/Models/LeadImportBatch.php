<?php

namespace App\Domain\Crm\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['organization_id', 'user_id', 'headers', 'rows', 'source_hash', 'source_settings', 'summary', 'errors', 'expires_at', 'committed_at'])]
class LeadImportBatch extends Model
{
    protected $table = 'crm_lead_import_batches';

    protected function casts(): array
    {
        return ['headers' => 'array', 'rows' => 'encrypted:array', 'source_settings' => 'array', 'summary' => 'array', 'errors' => 'array', 'expires_at' => 'datetime', 'committed_at' => 'datetime'];
    }
}
