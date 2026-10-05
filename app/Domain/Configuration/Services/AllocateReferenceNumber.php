<?php

namespace App\Domain\Configuration\Services;

use App\Models\Organization;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AllocateReferenceNumber
{
    public function handle(Organization $org, string $kind, string $operationKey): string
    {
        abort_unless(in_array($kind, ['invoice', 'estimate', 'document', 'recruitment'], true) && Str::isUuid($operationKey), 422);

        return DB::transaction(function () use ($org, $kind, $operationKey): string {
            Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
            $existing = DB::table('organization_allocated_numbers')->where('organization_id', $org->id)->where('kind', $kind)->where('operation_key', $operationKey)->first();
            if ($existing) {
                return $existing->reference;
            }
            $template = DB::table('organization_numbering_templates')->where('organization_id', $org->id)->where('kind', $kind)->where('active', true)->lockForUpdate()->first();
            if ($template) {
                if ($template->next_number > 999999999999) {
                    throw ValidationException::withMessages(['reference' => 'The configured numbering counter is exhausted.']);
                }
                $reference = $template->prefix.($kind === 'invoice' ? '-'.$org->id : '').($template->include_year ? '-'.now($org->timezone)->format('Y') : '').'-'.str_pad((string) $template->next_number, $template->padding, '0', STR_PAD_LEFT);
                DB::table('organization_numbering_templates')->where('id', $template->id)->increment('next_number');
            } else {
                $reference = strtoupper(substr($kind, 0, 3)).'-'.Str::upper(Str::random(16));
            }
            if (DB::table('organization_allocated_numbers')->where('organization_id', $org->id)->where('reference', $reference)->exists() || ($kind === 'invoice' && DB::table('invoices')->where('reference', $reference)->exists())) {
                throw ValidationException::withMessages(['reference' => 'This numbering template produces an existing reference; advance its counter.']);
            }
            DB::table('organization_allocated_numbers')->insert(['organization_id' => $org->id, 'kind' => $kind, 'reference' => $reference, 'operation_key' => $operationKey, 'created_at' => now(), 'updated_at' => now()]);

            return $reference;
        });
    }
}
