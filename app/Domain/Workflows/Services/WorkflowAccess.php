<?php

namespace App\Domain\Workflows\Services;

use App\Domain\Documents\Services\DocumentAccess;
use App\Domain\Workflows\Models\WorkflowPipeline;
use App\Domain\Workflows\Models\WorkflowRecord;
use App\Models\Document;
use App\Models\Organization;
use App\Models\User;

class WorkflowAccess
{
    public function allows(Organization $org, User $actor, WorkflowPipeline $pipeline, bool $write = false): bool
    {
        if ($pipeline->organization_id !== $org->id) {
            return false;
        }
        $role = $actor->organizations()->whereKey($org->id)->value('organization_user.role');
        if (! $role || ! in_array($role, $write ? $pipeline->edit_roles : $pipeline->read_roles, true)) {
            return false;
        }

        return match ($pipeline->kind) {
            'estimate', 'invoice' => $actor->can($write ? 'manageFinance' : 'viewFinance', $org),
            'document' => $actor->can($write ? 'manageInventory' : 'viewInventory', $org) || $actor->can($write ? 'manageOperations' : 'viewOperations', $org) || $actor->can($write ? 'manageTransactions' : 'viewTransactions', $org),
            'recruitment' => $actor->can('viewOperations', $org),
            default => false,
        };
    }

    public function record(Organization $org, User $actor, WorkflowRecord $record, bool $write = false): void
    {
        abort_unless($record->organization_id === $org->id && $this->allows($org, $actor, $record->pipeline, $write), $write ? 403 : 404);
        if ($record->document_id) {
            app(DocumentAccess::class)->authorize($org, $actor, Document::where('organization_id', $org->id)->findOrFail($record->document_id), $write);
        }
    }
}
