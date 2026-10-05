<?php

namespace App\Domain\Crm\Actions;

use App\Domain\Crm\Models\CustomField;
use App\Domain\Crm\Models\CustomFieldValue;
use App\Domain\Crm\Models\Deal;
use App\Domain\Crm\Models\DealPipeline;
use App\Domain\Crm\Models\DealStage;
use App\Domain\Crm\Models\DealStageHistory;
use App\Domain\Crm\Models\RecordFieldValue;
use App\Domain\Crm\Services\CrmEditPermission;
use App\Domain\Crm\Services\DealAccess;
use App\Domain\Crm\Services\LeadVisibility;
use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Models\CrmLead;
use App\Models\Listing;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ManageDeals
{
    public const CATEGORIES = ['offplan', 'secondary', 'resale', 'listing', 'leasing', 'other'];

    public const REQUIRED_FIELDS = ['title', 'first_name', 'last_name', 'email', 'phone', 'company', 'source', 'amount', 'expected_close_date', 'listing_id'];

    public function __construct(private DealAccess $access, private LeadVisibility $visibility, private RecordOrganizationAuditLog $audit, private ManageRecordFields $fields) {}

    /** @param array<string, mixed> $input */
    public function create(Organization $org, User $actor, array $input, ?CrmLead $lead = null): Deal
    {
        $data = $this->details($org, $input, true);
        $custom = Validator::make($input, ['custom_fields' => ['sometimes', 'array']])->validate()['custom_fields'] ?? [];
        $pipelineId = (int) Validator::make($input, ['pipeline_id' => ['required', 'integer']])->validate()['pipeline_id'];

        return DB::transaction(function () use ($org, $actor, $data, $pipelineId, $lead, $custom): Deal {
            Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
            if ($lead) {
                abort_unless(app(CrmEditPermission::class)->granted($org, $actor), 403);
                $lead = CrmLead::where('organization_id', $org->id)->lockForUpdate()->findOrFail($lead->id);
                abort_unless($this->visibility->canSeeLead($org, $actor, $lead->assigned_to), 404);
                $existing = Deal::where('organization_id', $org->id)->where('lead_id', $lead->id)->first();
                if ($existing) {
                    abort_unless($this->access->allows($org, $actor, $existing->pipeline, 'read', $existing->assigned_to), 404);

                    return $existing;
                }
                if (! $lead->pipeline->active || ! $lead->stage->active || $lead->stage->type !== 'won') {
                    $this->fail('lead_id', 'Move the lead to its final qualified (Won) stage before creating a deal.');
                }
            }
            $pipeline = DealPipeline::where('organization_id', $org->id)->where('active', true)->find($pipelineId);
            if (! $pipeline) {
                $this->fail('pipeline_id', 'Select an active deal pipeline in this organization.');
            }
            $this->category($org, $data['category']);
            $assignee = $data['assigned_to'] ?? $actor->id;
            $this->assignee($org, $assignee);
            if (isset($data['amount'])) {
                abort_unless($this->access->allows($org, $actor, $pipeline, 'amount', $assignee), 403);
            }
            abort_unless($this->access->allows($org, $actor, $pipeline, 'read', $assignee) && $this->access->allows($org, $actor, $pipeline, 'add', $assignee), 403);
            $initial = $pipeline->stages()->where('active', true)->where('is_initial', true)->where('type', 'normal')->first();
            if (! $initial) {
                $this->fail('pipeline_id', 'Configure an active normal initial stage first.');
            }
            if ($lead) {
                foreach ($pipeline->lead_field_mapping ?? [] as $target => $source) {
                    $sourceField = collect(app(ManageCustomFields::class)->visible($org, $actor))->first(fn ($field) => $field->key === $source);
                    $targetField = collect(app(ManageCustomFields::class)->visible($org, $actor, true, 'deal'))->first(fn ($field) => $field->key === $target);
                    if ($sourceField && $targetField && ! array_key_exists($target, $custom)) {
                        $value = CustomFieldValue::where('organization_id', $org->id)->where('lead_id', $lead->id)->where('field_id', $sourceField->id)->first()?->value;
                        if ($value !== null) {
                            $custom[$target] = $value;
                        }
                    }
                }
                $data = [...$lead->only('first_name', 'last_name', 'email', 'phone', 'company', 'source', 'notes', 'listing_id'), ...$data];
            }
            $this->listing($org, $data['listing_id'] ?? null);
            $deal = new Deal(['organization_id' => $org->id, 'pipeline_id' => $pipeline->id, 'current_stage_id' => $initial->id, 'lead_id' => $lead?->id, 'created_by' => $actor->id, ...$data, 'assigned_to' => $assignee, 'stage_changed_at' => now()]);
            $deal->save();
            $this->fields->write($org, $actor, $deal, $custom, true);
            $this->entry($org, $actor, $deal, $initial);
            $this->history($org, $actor, $deal, null, $initial, $lead ? 'Created from qualified lead.' : 'Created directly.');
            $this->audit->handle($org, $actor, 'crm.deal.created', $deal, ['lead_id' => $lead?->id, 'pipeline_id' => $pipeline->id, 'category' => $deal->category]);
            if ($lead) {
                $this->audit->handle($org, $actor, 'crm.lead.deal_created', $lead, ['deal_id' => $deal->id, 'pipeline_id' => $pipeline->id]);
            }

            return $deal->refresh();
        });
    }

    /** @param array<string, mixed> $input */
    public function update(Organization $org, User $actor, Deal $deal, array $input): Deal
    {
        $data = $this->details($org, $input, false);
        $custom = Validator::make($input, ['custom_fields' => ['sometimes', 'array']])->validate()['custom_fields'] ?? [];
        $version = $this->version($input);

        return DB::transaction(function () use ($org, $actor, $deal, $data, $version, $custom): Deal {
            $deal = $this->lock($org, $actor, $deal, 'edit', $version);
            if (isset($data['category']) && $data['category'] !== $deal->category) {
                $this->category($org, $data['category']);
            }
            $before = $deal->only(array_keys($data));
            if (array_key_exists('assigned_to', $data)) {
                $this->assignee($org, $data['assigned_to']);
                abort_unless($this->access->allows($org, $actor, $deal->pipeline, 'assign', $deal->assigned_to) && $this->access->allows($org, $actor, $deal->pipeline, 'read', $data['assigned_to']) && $this->access->allows($org, $actor, $deal->pipeline, 'edit', $data['assigned_to']), 403);
            }
            if (array_key_exists('amount', $data)) {
                abort_unless($this->access->allows($org, $actor, $deal->pipeline, 'amount', $deal->assigned_to), 403);
            }
            $this->listing($org, $data['listing_id'] ?? null);
            $deal->fill($data);
            $deal->version++;
            $deal->save();
            $this->fields->write($org, $actor, $deal, $custom);
            $this->entry($org, $actor, $deal, $deal->stage, false);
            $this->audit->handle($org, $actor, 'crm.deal.updated', $deal, ['changed_fields' => array_keys(array_filter($data, fn ($v, $k) => $before[$k] !== $v, ARRAY_FILTER_USE_BOTH))]);

            return $deal;
        });
    }

    /** @param array<string, mixed> $input */
    public function move(Organization $org, User $actor, Deal $deal, array $input, bool $transfer = false, bool $automated = false): Deal
    {
        $data = Validator::make($input, ['stage_id' => ['required', 'integer'], 'pipeline_id' => [$transfer ? 'required' : 'prohibited', 'integer'], 'confirmed' => [$transfer ? 'required' : 'sometimes', 'accepted'], 'lost_reason' => ['nullable', 'string', 'max:255'], 'notes' => ['nullable', 'string', 'max:2000']])->validate();
        $version = $this->version($input);

        return DB::transaction(function () use ($org, $actor, $deal, $data, $version, $transfer, $automated): Deal {
            $deal = $this->lock($org, $actor, $deal, $transfer ? 'transfer' : 'move', $version);
            $pipeline = DealPipeline::where('organization_id', $org->id)->where('active', true)->find($transfer ? (int) $data['pipeline_id'] : $deal->pipeline_id);
            if (! $pipeline) {
                $this->fail('pipeline_id', 'Select an active deal pipeline.');
            }
            if ($transfer && $pipeline->id === $deal->pipeline_id) {
                $this->fail('pipeline_id', 'Choose a different destination pipeline.');
            }
            if ($transfer) {
                abort_unless($this->access->allows($org, $actor, $pipeline, 'read', $deal->assigned_to) && $this->access->allows($org, $actor, $pipeline, 'add', $deal->assigned_to), 403);
            }
            $target = $pipeline->stages()->where('active', true)->find((int) $data['stage_id']);
            if (! $target) {
                $this->fail('stage_id', 'Choose an active stage in the selected pipeline.');
            }
            if ($deal->current_stage_id === $target->id) {
                $this->fail('stage_id', 'This deal is already in that stage.');
            }
            $from = $deal->stage;
            if (in_array($from->type, ['won', 'lost'], true) && ! in_array($target->type, ['normal', 'on_hold'], true)) {
                $this->fail('stage_id', 'Reopen the deal before changing its final outcome.');
            }
            if (in_array($from->type, ['won', 'lost'], true) && empty($data['notes'])) {
                $this->fail('notes', 'Provide a reason for reopening this deal.');
            }
            if ($target->type === 'lost' && empty($data['lost_reason'])) {
                $this->fail('lost_reason', 'Provide a lost reason.');
            }
            $this->fields->validateRequired($org, $actor, $deal);
            $this->entry($org, $actor, $deal, $target);
            $deal->update(['pipeline_id' => $pipeline->id, 'current_stage_id' => $target->id, 'stage_changed_at' => now(), 'closed_at' => in_array($target->type, ['won', 'lost'], true) ? now() : null, 'lost_reason' => $target->type === 'lost' ? $data['lost_reason'] : null, 'version' => $deal->version + 1]);
            $this->history($org, $actor, $deal, $from, $target, $data['notes'] ?? null, ! $automated);
            $this->audit->handle($org, $actor, $transfer ? 'crm.deal.transferred' : 'crm.deal.stage_changed', $deal, ['from_pipeline_id' => $from->pipeline_id, 'pipeline_id' => $pipeline->id, 'from_stage_id' => $from->id, 'stage_id' => $target->id]);

            return $deal;
        });
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function details(Organization $org, array $input, bool $creating): array
    {
        return Validator::make($input, [
            'title' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'], 'category' => [$creating ? 'required' : 'sometimes', 'string', 'max:24'],
            'first_name' => ['sometimes', 'nullable', 'string', 'max:100'], 'last_name' => ['sometimes', 'nullable', 'string', 'max:100'], 'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:50'], 'company' => ['sometimes', 'nullable', 'string', 'max:255'], 'source' => ['sometimes', 'nullable', 'string', 'max:100'], 'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'amount' => ['sometimes', 'nullable', 'decimal:0,2', 'regex:/^\d{1,16}(?:\.\d{1,2})?$/', 'min:0'], 'currency' => ['sometimes', 'string', 'regex:/^[A-Z]{3}$/'], 'expected_close_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'assigned_to' => ['sometimes', 'required', 'integer'], 'listing_id' => ['sometimes', 'nullable', 'integer'],
        ])->validate();
    }

    /** @param array<string, mixed> $input */
    private function version(array $input): int
    {
        return (int) Validator::make($input, ['expected_version' => ['required', 'integer', 'min:1']])->validate()['expected_version'];
    }

    private function category(Organization $org, string $code): void
    {
        if (! in_array($code, app(ManageCrmSettings::class)->activeCategories($org), true)) {
            $this->fail('category', 'Select an active organization category.');
        }
    }

    private function assignee(Organization $org, int $id): void
    {
        if (! $org->users()->where('users.id', $id)->exists()) {
            $this->fail('assigned_to', 'Select an organization member.');
        }
    }

    private function listing(Organization $org, ?int $id): void
    {
        if ($id !== null && ! Listing::where('organization_id', $org->id)->whereKey($id)->exists()) {
            $this->fail('listing_id', 'Select an organization listing.');
        }
    }

    private function lock(Organization $org, User $actor, Deal $deal, string $action, int $version): Deal
    {
        Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
        $deal = $this->access->query($org, $actor)->lockForUpdate()->findOrFail($deal->id);
        abort_unless($this->access->allows($org, $actor, $deal->pipeline, $action, $deal->assigned_to), 403);
        if ($deal->version !== $version) {
            $this->fail('expected_version', 'This deal changed. Refresh before saving.');
        }

        return $deal;
    }

    private function entry(Organization $org, User $actor, Deal $deal, DealStage $stage, bool $transition = true): void
    {
        if ($deal->exists) {
            app(ManageDealFinancialLinks::class)->requirement($org, $actor, $deal, $stage->financial_requirement);
        }
        $role = $actor->organizations()->whereKey($org->id)->value('organization_user.role');
        if ($transition && $stage->entry_roles !== null && ! in_array($role, $stage->entry_roles, true)) {
            $this->fail('stage_id', 'Your role cannot enter this stage.');
        }
        if ($transition && $deal->exists && $stage->allowed_from_stage_ids !== null && ! in_array($deal->current_stage_id, $stage->allowed_from_stage_ids, true)) {
            $this->fail('stage_id', 'This transition is not allowed.');
        }
        foreach ($stage->required_fields ?? [] as $field) {
            if (str_starts_with($field, 'custom:')) {
                $key = substr($field, 7);
                $definition = CustomField::where('organization_id', $org->id)->where('entity', 'deal')->where('active', true)->where('key', $key)->first();
                if ($definition) {
                    $value = RecordFieldValue::where('organization_id', $org->id)->where('entity', 'deal')->where('record_id', $deal->id)->where('field_id', $definition->id)->first()?->value;
                    if ($value === null || $value === '' || $value === []) {
                        $this->fail('custom_fields.'.$key, 'Complete this field before entering this stage.');
                    }
                }

                continue;
            }
            if ($deal->$field === null || $deal->$field === '') {
                $this->fail('stage_id', 'Complete '.$field.' before entering this stage.');
            }
        }
    }

    private function history(Organization $org, User $actor, Deal $deal, ?DealStage $from, DealStage $to, ?string $notes, bool $enqueue = true): void
    {
        $history = DealStageHistory::create(['organization_id' => $org->id, 'deal_id' => $deal->id, 'pipeline_id' => $to->pipeline_id, 'from_stage_id' => $from?->id, 'to_stage_id' => $to->id, 'changed_by' => $actor->id, 'notes' => $notes, 'changed_at' => now(), 'snapshot' => ['from_pipeline' => $from?->pipeline->name, 'pipeline' => $to->pipeline->name, 'from' => $from?->name, 'to' => $to->name, 'from_type' => $from?->type, 'to_type' => $to->type, 'assigned_to' => $deal->assigned_to, 'lost_reason' => $deal->lost_reason]]);
        if ($enqueue) {
            app(ManageDealAutomation::class)->enqueue($history);
        }
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
