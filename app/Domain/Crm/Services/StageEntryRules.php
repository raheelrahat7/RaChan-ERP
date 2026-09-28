<?php

namespace App\Domain\Crm\Services;

use App\Domain\Crm\Models\PipelineStage;
use App\Models\CrmLead;
use Illuminate\Validation\ValidationException;

class StageEntryRules
{
    public const FIELDS = ['first_name' => 'First name', 'last_name' => 'Last name', 'email' => 'Email', 'phone' => 'Phone', 'company' => 'Company', 'source' => 'Lead source', 'notes' => 'Notes'];

    /**
     * @return list<string>
     */
    public function reasons(CrmLead $lead, PipelineStage $stage, ?string $role, bool $checkTransition = true): array
    {
        $reasons = [];
        if ($checkTransition && $stage->allowed_from_stage_ids !== null && ! in_array($lead->current_stage_id, $stage->allowed_from_stage_ids, true)) {
            $reasons[] = 'This stage does not allow entry from the current stage.';
        }
        if ($stage->entry_roles !== null && ! in_array($role, $stage->entry_roles, true)) {
            $reasons[] = 'Your organization role cannot enter this stage.';
        }
        $missing = array_filter($stage->required_fields ?? [], fn ($field) => ! is_string($lead->getAttribute($field)) || trim($lead->getAttribute($field)) === '');
        if ($missing) {
            $reasons[] = 'Required lead details: '.implode(', ', array_map(fn ($field) => self::FIELDS[$field] ?? $field, $missing)).'. Update the lead details in Lead list.';
        }

        return $reasons;
    }

    public function enforce(CrmLead $lead, PipelineStage $stage, ?string $role, bool $checkTransition = true): void
    {
        $reasons = $this->reasons($lead, $stage, $role, $checkTransition);
        if ($reasons) {
            throw ValidationException::withMessages(['stage_id' => implode(' ', $reasons)]);
        }
    }
}
