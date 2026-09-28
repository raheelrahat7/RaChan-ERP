<?php

namespace App\Domain\Leasing\Models;

use App\Models\Document;
use App\Models\HandoverChecklist;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

#[Fillable(['organization_id', 'handover_checklist_id', 'area', 'condition', 'notes', 'recorded_by'])]
class HandoverInspectionItem extends Model
{
    /** @return BelongsTo<HandoverChecklist, $this> */
    public function handover(): BelongsTo
    {
        return $this->belongsTo(HandoverChecklist::class, 'handover_checklist_id');
    }

    /** @return BelongsTo<User, $this> */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /** @return MorphMany<Document, $this> */
    /** @return MorphMany<Document, $this> */
    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable')->orderBy('id');
    }
}
