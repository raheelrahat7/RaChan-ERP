<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable(['organization_id', 'documentable_type', 'documentable_id', 'uploaded_by', 'name', 'path', 'mime_type', 'size', 'root_document_id', 'version_number', 'version_reason', 'version_key', 'content_hash', 'archived_at', 'archived_by', 'archive_reason'])]
class Document extends Model
{
    protected function casts(): array
    {
        return ['version_number' => 'integer', 'archived_at' => 'datetime'];
    }

    /** @return MorphTo<Model, $this> */
    public function documentable(): MorphTo
    {
        return $this->morphTo();
    }
}
