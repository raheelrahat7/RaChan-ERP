<?php

namespace App\Domain\Documents\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['organization_id', 'document_id', 'requested_by', 'operation_key', 'version_number', 'content_hash', 'signers', 'reason', 'status', 'cancelled_at', 'cancelled_by', 'cancellation_reason'])]
class SignatureRequest extends Model
{
    protected function casts(): array
    {
        return ['signers' => 'array', 'version_number' => 'integer', 'cancelled_at' => 'immutable_datetime'];
    }
}
