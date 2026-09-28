<?php

namespace App\Domain\Accounting\Queries;

use App\Models\JournalEntry;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Builder;

class JournalRegister
{
    /** @return Builder<JournalEntry> */
    public function query(Organization $organization, ?string $from, ?string $to, ?string $event): Builder
    {
        return JournalEntry::query()->where('organization_id', $organization->id)->where('currency', 'AED')
            ->whereHas('lines')
            ->when($from, fn ($query) => $query->where('posted_on', '>=', $from))
            ->when($to, fn ($query) => $query->where('posted_on', '<=', $to))
            ->when($event, fn ($query) => $query->where('event', $event));
    }
}
