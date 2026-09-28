<?php

namespace App\Domain\Inventory\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['organization_id', 'user_id', 'kind', 'rows', 'errors', 'source_hash', 'expires_at', 'committed_at', 'created_ids'])]
class InventoryImportBatch extends Model
{
    protected function casts(): array
    {
        return ['rows' => 'array', 'errors' => 'array', 'created_ids' => 'array', 'expires_at' => 'immutable_datetime', 'committed_at' => 'immutable_datetime'];
    }
}
