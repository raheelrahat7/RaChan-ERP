<?php

namespace App\Domain\Crm\Actions;

use App\Domain\Crm\Models\CustomField;
use App\Domain\Crm\Models\Deal;
use App\Domain\Crm\Models\DealAccessRule;
use App\Domain\Crm\Models\DealAutomationRule;
use App\Domain\Crm\Models\DealPipeline;
use App\Domain\Crm\Models\DealStage;
use App\Domain\Crm\Models\DealStageHistory;
use App\Domain\Crm\Services\DealAccess;
use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Domain\Identity\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ManageDealPipelines
{
    public function __construct(private DealAccess $access, private RecordOrganizationAuditLog $audit) {}

    /** @param array<string, mixed> $input */
    public function save(Organization $org, User $actor, array $input, ?int $id = null): DealPipeline
    {
        $this->configure($org, $actor);
        $data = Validator::make($input, ['name' => ['required', 'string', 'max:100'], 'description' => ['nullable', 'string', 'max:2000'], 'position' => ['sometimes', 'integer', 'between:0,10000'], 'active' => ['sometimes', 'boolean'], 'is_default' => ['sometimes', 'boolean'], 'access_configured' => ['sometimes', 'boolean'], 'lead_field_mapping' => ['sometimes', 'array', 'max:100'], 'lead_field_mapping.*' => ['string', 'max:80']])->validate();

        return DB::transaction(function () use ($org, $actor, $data, $id): DealPipeline {
            Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
            $pipeline = $id ? DealPipeline::where('organization_id', $org->id)->findOrFail($id) : new DealPipeline(['organization_id' => $org->id, 'active' => true, 'is_default' => false]);
            if (isset($data['lead_field_mapping'])) {
                $leadFields = CustomField::where('organization_id', $org->id)->where('entity', 'lead')->where('active', true)->get()->keyBy('key');
                $dealFields = CustomField::where('organization_id', $org->id)->where('entity', 'deal')->where('active', true)->get()->keyBy('key');
                foreach ($data['lead_field_mapping'] as $target => $source) {
                    if (! $dealFields->has($target) || ! $leadFields->has($source) || $dealFields[$target]->type !== $leadFields[$source]->type) {
                        $this->fail('lead_field_mapping', 'Map active organization fields with matching types, from lead keys to deal keys.');
                    }
                }
            }
            $pipeline->fill($data);
            if (! DealPipeline::where('organization_id', $org->id)->exists()) {
                $pipeline->is_default = true;
            }
            if ($pipeline->is_default && ! $pipeline->active) {
                $this->fail('active', 'The default pipeline must remain active.');
            }
            if (($data['is_default'] ?? false) && ! ($data['active'] ?? $pipeline->active ?? true)) {
                $this->fail('active', 'Activate a pipeline before making it default.');
            }
            if ($id && $pipeline->getOriginal('is_default') && ! $pipeline->is_default) {
                $this->fail('is_default', 'Choose another default pipeline first.');
            }
            if ($pipeline->is_default) {
                DealPipeline::where('organization_id', $org->id)->whereKeyNot($pipeline->id ?? 0)->update(['is_default' => false]);
            }
            $pipeline->save();
            if (! $id) {
                foreach ([['New', 'normal', '#38bdf8'], ['Deal won', 'won', '#65a30d'], ['Deal lost', 'lost', '#ef4444']] as $position => [$name, $type, $color]) {
                    $pipeline->stages()->create(['name' => $name, 'type' => $type, 'color' => $color, 'position' => $position + 1, 'is_initial' => $position === 0]);
                }
            }
            $this->audit->handle($org, $actor, 'crm.deal_pipeline.saved', $pipeline, $data);

            return $pipeline->refresh();
        });
    }

    /** @param array<string, mixed> $input */
    public function stage(Organization $org, User $actor, int $pipelineId, array $input, ?int $id = null): DealStage
    {
        $this->configure($org, $actor);
        $data = Validator::make($input, [
            'name' => ['required', 'string', 'max:100'], 'type' => ['required', Rule::in(['normal', 'on_hold', 'won', 'lost'])], 'color' => ['required', 'regex:/^#[a-fA-F0-9]{6}$/'],
            'position' => ['required', 'integer', 'between:1,10000'], 'active' => ['required', 'boolean'], 'is_initial' => ['sometimes', 'boolean'],
            'allowed_from_stage_ids' => ['sometimes', 'nullable', 'array', 'max:100'], 'allowed_from_stage_ids.*' => ['integer', 'distinct'],
            'entry_roles' => ['sometimes', 'nullable', 'array'], 'entry_roles.*' => [Rule::in(array_column(OrganizationRole::cases(), 'value')), 'distinct'],
            'financial_requirement' => ['sometimes', 'nullable', Rule::in(ManageDealFinancialLinks::REQUIREMENTS)],
            'required_fields' => ['sometimes', 'array'], 'required_fields.*' => [Rule::in([...ManageDeals::REQUIRED_FIELDS, ...CustomField::where('organization_id', $org->id)->where('entity', 'deal')->where('active', true)->pluck('key')->map(fn ($key) => 'custom:'.$key)->all()]), 'distinct'],
        ])->validate();

        return DB::transaction(function () use ($org, $actor, $pipelineId, $data, $id): DealStage {
            Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
            $pipeline = DealPipeline::where('organization_id', $org->id)->findOrFail($pipelineId);
            $stage = $id ? $pipeline->stages()->findOrFail($id) : $pipeline->stages()->make();
            if ($stage->is_initial && (! $data['active'] || $data['type'] !== 'normal' || (array_key_exists('is_initial', $data) && ! $data['is_initial']))) {
                $this->fail('is_initial', 'Choose another active normal initial stage first.');
            }
            if (($data['is_initial'] ?? false) && (! $data['active'] || $data['type'] !== 'normal')) {
                $this->fail('is_initial', 'The initial stage must be active and normal.');
            }
            if ($id && $stage->type !== $data['type'] && $this->used($id)) {
                $this->fail('type', 'Used stages cannot change outcome type.');
            }
            if (($data['is_initial'] ?? $stage->is_initial) && ($data['financial_requirement'] ?? $stage->financial_requirement)) {
                $this->fail('financial_requirement', 'An initial stage cannot require linked financial records.');
            }
            $sources = $data['allowed_from_stage_ids'] ?? [];
            if (count($sources) !== $pipeline->stages()->whereKey($sources)->where('id', '!=', $id ?? 0)->count()) {
                $this->fail('allowed_from_stage_ids', 'Select other stages in this pipeline.');
            }
            if ($data['is_initial'] ?? false) {
                $pipeline->stages()->update(['is_initial' => false]);
            }
            $stage->fill($data)->save();
            $this->audit->handle($org, $actor, 'crm.deal_stage.saved', $stage, $data);

            return $stage;
        });
    }

    /** @param array<string, mixed> $input */
    public function access(Organization $org, User $actor, int $pipelineId, array $input): DealAccessRule
    {
        $this->configure($org, $actor);
        $data = Validator::make($input, ['principal_type' => ['required', Rule::in(['role', 'user', 'team', 'subdepartment', 'department'])], 'principal_id' => ['required', 'string', 'max:40'], 'permissions' => ['required', 'array:'.implode(',', DealAccess::ACTIONS)], 'permissions.*' => ['required', Rule::in(DealAccess::SCOPES)]])->validate();

        return DB::transaction(function () use ($org, $actor, $pipelineId, $data): DealAccessRule {
            Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
            DealPipeline::where('organization_id', $org->id)->findOrFail($pipelineId)->update(['access_configured' => true]);
            $valid = match ($data['principal_type']) {
                'role' => in_array($data['principal_id'], array_column(OrganizationRole::cases(), 'value'), true),
                'user' => ctype_digit($data['principal_id']) && $org->users()->where('users.id', $data['principal_id'])->exists(),
                default => ctype_digit($data['principal_id']) && DB::table('crm_'.match ($data['principal_type']) {
                    'team' => 'teams', 'department' => 'departments', default => 'subdepartments'
                })->where('organization_id', $org->id)->where('id', $data['principal_id'])->exists(),
            };
            if (! $valid) {
                $this->fail('principal_id', 'Select a role or member/group belonging to this organization.');
            }
            $rule = DealAccessRule::updateOrCreate(['organization_id' => $org->id, 'pipeline_id' => $pipelineId, 'principal_type' => $data['principal_type'], 'principal_id' => $data['principal_id']], ['permissions' => $data['permissions']]);
            $this->audit->handle($org, $actor, 'crm.deal_access.saved', $rule, $data);

            return $rule;
        });
    }

    public function delete(Organization $org, User $actor, string $kind, int $id): void
    {
        $this->configure($org, $actor);
        DB::transaction(function () use ($org, $actor, $kind, $id): void {
            Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
            if ($kind === 'pipeline') {
                $item = DealPipeline::where('organization_id', $org->id)->findOrFail($id);
                if ($item->is_default || Deal::where('pipeline_id', $id)->exists() || DealStageHistory::where('pipeline_id', $id)->exists() || DealAutomationRule::where('pipeline_id', $id)->exists()) {
                    $this->fail('configuration', 'Deactivate used pipelines instead.');
                }
            } elseif ($kind === 'stage') {
                $item = DealStage::whereIn('pipeline_id', DealPipeline::where('organization_id', $org->id)->select('id'))->findOrFail($id);
                if ($item->is_initial || $this->used($id) || DealStage::whereJsonContains('allowed_from_stage_ids', $id)->exists()) {
                    $this->fail('configuration', 'Deactivate used stages instead.');
                }
            } else {
                $item = DealAccessRule::where('organization_id', $org->id)->findOrFail($id);
            }
            $this->audit->handle($org, $actor, 'crm.deal_'.$kind.'.deleted', $item);
            $item->delete();
        });
    }

    private function used(int $id): bool
    {
        return DealAutomationRule::where(fn ($query) => $query->where('stage_id', $id)->orWhere('target_stage_id', $id))->exists() || Deal::where('current_stage_id', $id)->exists() || DealStageHistory::where(fn ($q) => $q->where('from_stage_id', $id)->orWhere('to_stage_id', $id))->exists();
    }

    private function configure(Organization $org, User $actor): void
    {
        abort_unless($this->access->administrator($org, $actor), 403);
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
