<?php

namespace App\Domain\Workflows\Actions;

use App\Domain\Configuration\Services\AllocateReferenceNumber;
use App\Domain\Documents\Services\DocumentAccess;
use App\Domain\Finance\Services\EstimatePricing;
use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Domain\Identity\Enums\OrganizationPermission;
use App\Domain\Workflows\Models\WorkflowPipeline;
use App\Domain\Workflows\Models\WorkflowRecord;
use App\Domain\Workflows\Models\WorkflowStage;
use App\Domain\Workflows\Services\WorkflowAccess;
use App\Models\CrmContact;
use App\Models\Document;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ManageReferenceWorkflows
{
    public const KINDS = ['estimate', 'invoice', 'document', 'recruitment'];

    public const DETAIL_FIELDS = ['name', 'email', 'phone', 'job_title', 'location', 'resume_reference', 'interview_notes', 'offer_reference', 'joined_on', 'notes', 'valid_until', 'lines', 'currency'];

    /** @param array<string, mixed> $input */
    public function pipeline(Organization $org, User $actor, array $input, ?int $id = null): WorkflowPipeline
    {
        Gate::forUser($actor)->authorize('manageSettings', $org);
        $data = Validator::make($input, ['kind' => ['required', 'in:'.implode(',', self::KINDS)], 'name' => ['required', 'string', 'max:100'], 'active' => ['sometimes', 'boolean'], 'position' => ['sometimes', 'integer', 'between:0,10000'], 'read_roles' => ['sometimes', 'array'], 'read_roles.*' => ['in:owner,administrator,manager,member,viewer', 'distinct'], 'field_definitions' => ['sometimes', 'array', 'max:50'], 'field_definitions.*' => ['array:key,name,type,required,options'], 'field_definitions.*.key' => ['required', 'regex:/^[a-z][a-z0-9_]{0,79}$/', 'distinct'], 'field_definitions.*.name' => ['required', 'string', 'max:100'], 'field_definitions.*.type' => ['required', 'in:text,number,date,checkbox,select'], 'field_definitions.*.required' => ['required', 'boolean'], 'field_definitions.*.options' => ['sometimes', 'array', 'max:100'], 'field_definitions.*.options.*' => ['string', 'max:100', 'distinct'], 'edit_roles' => ['sometimes', 'array'], 'edit_roles.*' => ['in:owner,administrator,manager,member,viewer', 'distinct']])->validate();

        return DB::transaction(function () use ($org, $actor, $data, $id): WorkflowPipeline {
            Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
            $pipeline = $id ? WorkflowPipeline::where('organization_id', $org->id)->findOrFail($id) : new WorkflowPipeline(['organization_id' => $org->id, 'read_roles' => ['owner', 'administrator'], 'edit_roles' => ['owner', 'administrator']]);
            if ($id && $pipeline->kind !== $data['kind']) {
                $this->fail('kind', 'Pipeline kinds cannot change.');
            }
            if ($data['kind'] === 'document') {
                abort_unless($actor->hasOrganizationPermission($org, OrganizationPermission::ManageDocumentTemplates), 403);
            }
            if ($id && isset($data['field_definitions'])) {
                $nextFields = array_column($data['field_definitions'], null, 'key');
                foreach ($pipeline->field_definitions ?? [] as $field) {
                    $next = $nextFields[$field['key']] ?? null;
                    if ((! $next || $next['type'] !== $field['type']) && WorkflowRecord::where('pipeline_id', $pipeline->id)->exists()) {
                        $this->fail('field_definitions', 'Retain field keys and types once this pipeline contains records. Names, requirements and options remain editable.');
                    }
                }
                foreach ($pipeline->stages as $configuredStage) {
                    foreach ($configuredStage->required_fields ?? [] as $required) {
                        if (str_starts_with($required, 'custom:') && ! array_key_exists(substr($required, 7), $nextFields)) {
                            $this->fail('field_definitions', 'Remove stage requirements before removing a field.');
                        }
                    }
                }
            }
            $pipeline->fill($data);
            if (array_diff($pipeline->edit_roles, $pipeline->read_roles)) {
                $this->fail('edit_roles', 'Editing roles must also be able to read.');
            }
            $pipeline->save();
            if (! $id) {
                foreach ([['New', 'normal'], ['Success', 'success'], ['Failed', 'failure']] as $position => [$name, $type]) {
                    $pipeline->stages()->create(['name' => $name, 'type' => $type, 'position' => $position + 1, 'is_initial' => $position === 0]);
                }
            }
            app(RecordOrganizationAuditLog::class)->handle($org, $actor, 'workflows.pipeline.saved', $pipeline);

            return $pipeline->load('stages');
        });
    }

    /** @param array<string, mixed> $input */
    public function stage(Organization $org, User $actor, int $pipelineId, array $input, ?int $id = null): WorkflowStage
    {
        Gate::forUser($actor)->authorize('manageSettings', $org);
        $data = Validator::make($input, ['name' => ['required', 'string', 'max:100'], 'type' => ['required', 'in:normal,success,failure'], 'active' => ['required', 'boolean'], 'position' => ['required', 'integer', 'between:1,10000'], 'color' => ['required', 'regex:/^#[a-fA-F0-9]{6}$/'], 'is_initial' => ['sometimes', 'boolean'], 'allowed_from_stage_ids' => ['sometimes', 'nullable', 'array', 'max:100'], 'allowed_from_stage_ids.*' => ['integer', 'distinct'], 'entry_roles' => ['sometimes', 'nullable', 'array'], 'entry_roles.*' => ['in:owner,administrator,manager,member,viewer', 'distinct'], 'required_fields' => ['sometimes', 'array'], 'required_fields.*' => ['string', 'max:100', 'distinct'], 'source_statuses' => ['sometimes', 'nullable', 'array', 'max:10'], 'source_statuses.*' => ['in:draft,posted,partial,paid,void', 'distinct']])->validate();

        return DB::transaction(function () use ($org, $actor, $pipelineId, $data, $id): WorkflowStage {
            Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
            $pipeline = WorkflowPipeline::where('organization_id', $org->id)->findOrFail($pipelineId);
            $stage = $id ? $pipeline->stages()->findOrFail($id) : $pipeline->stages()->make();
            if ($stage->is_initial && (! $data['active'] || $data['type'] !== 'normal' || (isset($data['is_initial']) && ! $data['is_initial']))) {
                $this->fail('is_initial', 'Select another initial stage first.');
            }
            if (($data['is_initial'] ?? false) && (! $data['active'] || $data['type'] !== 'normal')) {
                $this->fail('is_initial', 'The initial stage must be active and normal.');
            }
            if ($id && $stage->type !== $data['type'] && (WorkflowRecord::where('stage_id', $id)->exists() || DB::table('reference_workflow_histories')->where('from_stage_id', $id)->orWhere('to_stage_id', $id)->exists())) {
                $this->fail('type', 'Used outcome types cannot change.');
            }
            $allowedFields = [...self::DETAIL_FIELDS, ...array_map(fn ($field) => 'custom:'.$field['key'], $pipeline->field_definitions ?? [])];
            if (array_diff($data['required_fields'] ?? [], $allowedFields)) {
                $this->fail('required_fields', 'Choose fields defined in this pipeline.');
            }
            if (! empty($data['source_statuses']) && $pipeline->kind !== 'invoice') {
                $this->fail('source_statuses', 'Source invoice statuses apply only to invoice workflows.');
            }
            $sources = $data['allowed_from_stage_ids'] ?? [];
            if (count($sources) !== $pipeline->stages()->whereKey($sources)->where('id', '!=', $id ?? 0)->count()) {
                $this->fail('allowed_from_stage_ids', 'Choose other stages in this pipeline.');
            }
            if ($data['is_initial'] ?? false) {
                $pipeline->stages()->update(['is_initial' => false]);
            }
            $stage->fill($data)->save();
            app(RecordOrganizationAuditLog::class)->handle($org, $actor, 'workflows.stage.saved', $stage);

            return $stage;
        });
    }

    /** @param array<string, mixed> $input */
    public function save(Organization $org, User $actor, array $input, ?int $id = null): WorkflowRecord
    {
        $data = Validator::make($input, ['pipeline_id' => [$id ? 'sometimes' : 'required', 'integer'], 'title' => ['required', 'string', 'max:255'], 'assigned_to' => ['required', 'integer'], 'details' => ['present', 'array'], 'operation_key' => [$id ? 'prohibited' : 'required', 'uuid'], 'expected_version' => [$id ? 'required' : 'prohibited', 'integer', 'min:1'], 'document_id' => ['nullable', 'integer'], 'invoice_id' => ['nullable', 'integer'], 'contact_id' => ['nullable', 'integer']])->validate();

        return DB::transaction(function () use ($org, $actor, $data, $id): WorkflowRecord {
            Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
            $record = $id ? WorkflowRecord::where('organization_id', $org->id)->lockForUpdate()->findOrFail($id) : new WorkflowRecord(['organization_id' => $org->id, 'created_by' => $actor->id]);
            $pipeline = WorkflowPipeline::where('organization_id', $org->id)->where('active', true)->findOrFail((int) ($data['pipeline_id'] ?? $record->pipeline_id));
            abort_unless(app(WorkflowAccess::class)->allows($org, $actor, $pipeline, true), 403);
            if ($id) {
                app(WorkflowAccess::class)->record($org, $actor, $record, true);
                if ($pipeline->id !== $record->pipeline_id) {
                    $this->fail('pipeline_id', 'Use a confirmed transfer to change the pipeline.');
                }
                if ($record->version !== $data['expected_version']) {
                    $this->fail('expected_version', 'Refresh this record before saving.');
                }
                if ($pipeline->kind === 'estimate' && $record->invoice_id !== null) {
                    $this->fail('record', 'Converted estimates are read-only. Create a revised estimate instead.');
                }
                foreach (['document_id', 'invoice_id', 'contact_id'] as $link) {
                    if (array_key_exists($link, $data) && $data[$link] !== $record->$link) {
                        $this->fail($link, 'Record links cannot change after creation.');
                    }
                }
                if ($record->closed_at) {
                    $this->fail('record', 'Reopen closed records before editing.');
                }
            }
            if (! $org->users()->where('users.id', $data['assigned_to'])->exists()) {
                $this->fail('assigned_to', 'Choose an organization member.');
            }
            $details = $this->details($pipeline, $data['details']);
            $data['details'] = $details;
            if (isset($data['contact_id'])) {
                CrmContact::where('organization_id', $org->id)->findOrFail($data['contact_id']);
            }
            if ($pipeline->kind === 'document') {
                $document = Document::where('organization_id', $org->id)->findOrFail((int) ($data['document_id'] ?? $record->document_id));
                app(DocumentAccess::class)->authorize($org, $actor, $document, true);
                $data['document_id'] = $document->id;
            } elseif (! empty($data['document_id'])) {
                $this->fail('document_id', 'Document links belong to document workflows.');
            }
            if ($pipeline->kind === 'invoice') {
                $invoice = Invoice::where('organization_id', $org->id)->findOrFail((int) ($data['invoice_id'] ?? $record->invoice_id));
                $data['invoice_id'] = $invoice->id;
            } elseif (! empty($data['invoice_id'])) {
                $this->fail('invoice_id', 'Invoice links belong to invoice workflows.');
            }
            if (! $id) {
                $existing = WorkflowRecord::where('organization_id', $org->id)->where('operation_key', $data['operation_key'])->first();
                if ($existing) {
                    app(WorkflowAccess::class)->record($org, $actor, $existing);
                    if ($existing->pipeline_id !== $pipeline->id || $existing->title !== $data['title'] || $existing->assigned_to !== $data['assigned_to'] || $existing->details != $details || $existing->document_id !== ($data['document_id'] ?? null) || $existing->invoice_id !== ($data['invoice_id'] ?? null) || $existing->contact_id !== ($data['contact_id'] ?? null)) {
                        $this->fail('operation_key', 'This key belongs to different record details.');
                    }

                    return $existing;
                }
                $stage = $pipeline->stages()->where('active', true)->where('is_initial', true)->where('type', 'normal')->firstOrFail();
                $record->fill(['pipeline_id' => $pipeline->id, 'stage_id' => $stage->id, 'stage_changed_at' => now(), 'reference' => app(AllocateReferenceNumber::class)->handle($org, $pipeline->kind, $data['operation_key'])]);
            }
            unset($data['expected_version'], $data['pipeline_id']);
            $record->fill($data);
            if ($id) {
                $record->version++;
            }
            $this->entry($org, $actor, $record, $record->stage);
            $record->save();
            if (! $id) {
                $this->history($org, $actor, $record, null, null);
            }
            app(RecordOrganizationAuditLog::class)->handle($org, $actor, 'workflows.record.saved', $record, ['kind' => $pipeline->kind]);

            return $record->load(['stage', 'pipeline']);
        });
    }

    /** @param array<string, mixed> $input */
    public function move(Organization $org, User $actor, int $id, array $input): WorkflowRecord
    {
        $data = Validator::make($input, ['stage_id' => ['required', 'integer'], 'pipeline_id' => ['sometimes', 'integer'], 'confirmed' => ['sometimes', 'accepted'], 'expected_version' => ['required', 'integer'], 'reason' => ['nullable', 'string', 'max:2000']])->validate();

        return DB::transaction(function () use ($org, $actor, $id, $data): WorkflowRecord {
            Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
            $record = WorkflowRecord::where('organization_id', $org->id)->lockForUpdate()->findOrFail($id);
            app(WorkflowAccess::class)->record($org, $actor, $record, true);
            if ($record->version !== $data['expected_version']) {
                $this->fail('expected_version', 'Refresh before moving this record.');
            }
            $pipeline = WorkflowPipeline::where('organization_id', $org->id)->where('active', true)->findOrFail((int) ($data['pipeline_id'] ?? $record->pipeline_id));
            abort_unless(app(WorkflowAccess::class)->allows($org, $actor, $pipeline, true), 403);
            if ($pipeline->kind !== $record->pipeline->kind) {
                $this->fail('pipeline_id', 'Transfer only between workflows of the same kind.');
            }
            if ($pipeline->id !== $record->pipeline_id && ! ($data['confirmed'] ?? false)) {
                $this->fail('confirmed', 'Confirm the pipeline transfer.');
            }
            $target = $pipeline->stages()->where('active', true)->findOrFail((int) $data['stage_id']);
            if ($target->id === $record->stage_id) {
                $this->fail('stage_id', 'Already in this stage.');
            }
            if (($record->closed_at || $target->type === 'failure') && blank($data['reason'] ?? null)) {
                $this->fail('reason', 'A reason is required for failure or reopening.');
            }
            if ($record->closed_at && $target->type !== 'normal') {
                $this->fail('stage_id', 'Reopen into a normal stage first.');
            }
            $from = $record->stage;
            $this->entry($org, $actor, $record, $target);
            $record->update(['pipeline_id' => $pipeline->id, 'stage_id' => $target->id, 'stage_changed_at' => now(), 'closed_at' => $target->type === 'normal' ? null : now(), 'version' => $record->version + 1]);
            $this->history($org, $actor, $record, $from, $data['reason'] ?? null);
            app(RecordOrganizationAuditLog::class)->handle($org, $actor, 'workflows.record.moved', $record, ['from' => $from->id, 'to' => $target->id]);

            return $record->load(['pipeline', 'stage']);
        });
    }

    /** @param array<string, mixed> $details
     * @return array<string, mixed> */
    private function details(WorkflowPipeline $pipeline, array $details): array
    {
        $custom = $details['custom_fields'] ?? [];
        unset($details['custom_fields']);
        $rules = [];
        $keys = [];
        foreach ($pipeline->field_definitions ?? [] as $field) {
            $keys[] = $field['key'];
            $rules[$field['key']] = [$field['required'] ? 'required' : 'nullable', ...match ($field['type']) {
                'number' => ['numeric', 'between:-999999999999,999999999999'],
                'date' => ['date_format:Y-m-d'],
                'checkbox' => ['boolean'],
                'select' => ['string', Rule::in($field['options'] ?? [])],
                default => ['string', 'max:5000'],
            }];
        }
        $custom = Validator::make(['values' => $custom], ['values' => ['array']])->validate()['values'];
        if (array_diff(array_keys($custom), $keys)) {
            $this->fail('details.custom_fields', 'Choose fields defined in this pipeline.');
        }
        $custom = Validator::make($custom, $rules)->validate();
        $kind = $pipeline->kind;
        if ($kind === 'estimate') {
            return [...app(EstimatePricing::class)->calculate($details), 'custom_fields' => $custom];
        }
        $rules = ['notes' => ['nullable', 'string', 'max:5000']];
        if ($kind === 'recruitment') {
            $rules = [...$rules, 'name' => ['required', 'string', 'max:255'], 'email' => ['nullable', 'email', 'max:255'], 'phone' => ['nullable', 'string', 'max:50'], 'job_title' => ['required', 'string', 'max:120'], 'location' => ['nullable', 'string', 'max:120'], 'resume_reference' => ['nullable', 'string', 'max:255'], 'interview_notes' => ['nullable', 'string', 'max:5000'], 'offer_reference' => ['nullable', 'string', 'max:255'], 'joined_on' => ['nullable', 'date_format:Y-m-d']];
        }
        if (array_diff(array_keys($details), array_keys($rules))) {
            $this->fail('details', 'Unknown fields are not accepted.');
        }

        return [...Validator::make($details, $rules)->validate(), 'custom_fields' => $custom];
    }

    private function entry(Organization $org, User $actor, WorkflowRecord $record, WorkflowStage $stage): void
    {
        $role = $actor->organizations()->whereKey($org->id)->value('organization_user.role');
        if ($stage->entry_roles !== null && ! in_array($role, $stage->entry_roles, true)) {
            $this->fail('stage_id', 'Your role cannot enter this stage.');
        }
        if ($record->exists && $stage->id !== $record->stage_id && $stage->allowed_from_stage_ids !== null && ! in_array($record->stage_id, $stage->allowed_from_stage_ids, true)) {
            $this->fail('stage_id', 'This transition is not allowed.');
        }
        if ($stage->source_statuses !== null && $stage->source_statuses !== []) {
            $invoice = Invoice::where('organization_id', $org->id)->findOrFail($record->invoice_id);
            if (! in_array($invoice->status, $stage->source_statuses, true)) {
                $this->fail('stage_id', 'The linked invoice does not meet this stage status requirement.');
            }
        }
        foreach ($stage->required_fields ?? [] as $key) {
            $value = str_starts_with($key, 'custom:') ? ($record->details['custom_fields'][substr($key, 7)] ?? null) : ($record->details[$key] ?? null);
            if (blank($value)) {
                $this->fail('details.'.$key, 'Complete this field before entering this stage.');
            }
        }
    }

    private function history(Organization $org, User $actor, WorkflowRecord $record, ?WorkflowStage $from, ?string $reason): void
    {
        $record->unsetRelation('stage');
        DB::table('reference_workflow_histories')->insert(['organization_id' => $org->id, 'record_id' => $record->id, 'from_stage_id' => $from?->id, 'to_stage_id' => $record->stage_id, 'actor_id' => $actor->id, 'reason' => $reason, 'snapshot' => json_encode(['from' => $from?->name, 'to' => $record->stage->name, 'title' => $record->title, 'reference' => $record->reference, 'from_pipeline' => $from?->pipeline->name, 'to_pipeline' => $record->stage->pipeline->name]), 'created_at' => now()]);
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
