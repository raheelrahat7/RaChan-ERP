<?php

namespace App\Domain\Crm\Actions;

use App\Domain\Crm\Events\LeadStageChanged;
use App\Domain\Crm\Models\LeadStageHistory;
use App\Domain\Crm\Models\LostReason;
use App\Domain\Crm\Models\Pipeline;
use App\Domain\Crm\Models\PipelineStage;
use App\Domain\Crm\Services\AssignLeadOnStageEntry;
use App\Domain\Crm\Services\LeadVisibility;
use App\Domain\Crm\Services\StageEntryRules;
use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Models\CrmAccount;
use App\Models\CrmContact;
use App\Models\CrmLead;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ManageLeadPipeline
{
    public function __construct(private RecordOrganizationAuditLog $audit, private StageEntryRules $rules, private LeadVisibility $visibility, private AssignLeadOnStageEntry $autoAssign, private ManageCustomFields $fields) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(Organization $org, User $actor, array $data): CrmLead
    {
        return DB::transaction(function () use ($org, $actor, $data): CrmLead {
            Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
            $custom = $data['custom_fields'] ?? [];
            unset($data['custom_fields']);
            if ($this->visibility->restricted($org, $actor)) {
                $data['assigned_to'] = $actor->id;
            }
            $lead = $org->leads()->create($data);
            $this->rules->enforce($lead, $lead->stage, $this->role($org, $actor), false);
            $this->autoAssign->handle($org, $lead, $lead->stage);
            $this->fields->writeValues($org, $actor, $lead, $custom, true);
            $this->record($org, $actor, $lead, null, $lead->stage, null, 'Lead created.');
            $this->audit->handle($org, $actor, 'crm.lead.created', $lead);

            return $lead;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createImported(Organization $org, array $data): CrmLead
    {
        return DB::transaction(function () use ($org, $data): CrmLead {
            Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
            $lead = $org->leads()->create($data);
            // Meta submissions are retained even when manual entry would require more fields.
            $this->autoAssign->handle($org, $lead, $lead->stage);
            $this->record($org, null, $lead, null, $lead->stage, null, 'Imported from Meta lead form.');
            $this->audit->handle($org, null, 'crm.lead.imported', $lead, ['meta_lead_id' => $lead->meta_lead_id]);

            return $lead;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createPublicInquiry(Organization $org, array $data, string $historyNote = 'Submitted through a public listing page.'): CrmLead
    {
        return DB::transaction(function () use ($org, $data, $historyNote): CrmLead {
            Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
            $lead = $org->leads()->create($data);
            $this->autoAssign->handle($org, $lead, $lead->stage);
            $this->record($org, null, $lead, null, $lead->stage, null, $historyNote);
            $this->audit->handle($org, null, 'crm.lead.public_inquiry_created', $lead, ['listing_id' => $lead->listing_id]);

            return $lead;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function move(Organization $org, User $actor, CrmLead $lead, array $data): void
    {
        DB::transaction(function () use ($org, $actor, $lead, $data): void {
            $lead = $this->lockLead($org, $actor, $lead);
            if ($lead->converted_at) {
                $this->fail('stage_id', 'Converted leads cannot change stage.');
            }
            if ($lead->current_stage_id !== (int) $data['expected_stage_id']) {
                $this->fail('stage_id', 'This lead changed since you opened it. Refresh and try again.');
            }
            $pipeline = Pipeline::where('organization_id', $org->id)->where('active', true)->find($lead->pipeline_id);
            if (! $pipeline) {
                $this->fail('stage_id', 'The pipeline is inactive.');
            }
            $stage = $pipeline->stages()->where('active', true)->find((int) $data['stage_id']);
            if (! $stage) {
                $this->fail('stage_id', 'Select an active stage in this lead’s pipeline.');
            }
            if ($stage->id === $lead->current_stage_id) {
                $this->fail('stage_id', 'The lead is already in this stage.');
            }
            if ($lead->stage->type === 'lost' && in_array($stage->type, ['won', 'lost'])) {
                $this->fail('stage_id', 'Reopen a lost lead to a nonterminal stage first.');
            }
            $this->rules->enforce($lead, $stage, $this->role($org, $actor));
            $reason = null;
            if ($stage->type === 'lost') {
                $reason = $pipeline->reasons()->where('active', true)->find(isset($data['lost_reason_id']) ? (int) $data['lost_reason_id'] : null);
                if (! $reason) {
                    $this->fail('lost_reason_id', 'Select an active lost reason in this pipeline.');
                }
            }
            $from = $lead->stage;
            $lead->update(['current_stage_id' => $stage->id, 'lost_reason_id' => $reason?->id, 'stage_changed_at' => now()]);
            $this->autoAssign->handle($org, $lead, $stage);
            $this->record($org, $actor, $lead, $from, $stage, $reason, $data['notes'] ?? null);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function transfer(Organization $org, User $actor, CrmLead $lead, array $data): void
    {
        DB::transaction(function () use ($org, $actor, $lead, $data): void {
            $lead = $this->lockLead($org, $actor, $lead);
            if ($lead->converted_at) {
                $this->fail('pipeline_id', 'Converted leads cannot be transferred.');
            }
            if ($lead->pipeline_id !== (int) $data['expected_pipeline_id'] || $lead->current_stage_id !== (int) $data['expected_stage_id']) {
                $this->fail('pipeline_id', 'This lead changed since you opened it. Refresh and try again.');
            }
            if ($lead->pipeline_id === (int) $data['pipeline_id']) {
                $this->fail('pipeline_id', 'Choose a different destination pipeline.');
            }
            $pipeline = Pipeline::where('organization_id', $org->id)->where('active', true)->find((int) $data['pipeline_id']);
            if (! $pipeline) {
                $this->fail('pipeline_id', 'Choose an active pipeline in this organization.');
            }
            $stage = $pipeline->stages()->where('active', true)->find((int) $data['stage_id']);
            if (! $stage) {
                $this->fail('stage_id', 'Choose an active stage in the destination pipeline.');
            }
            if ($lead->stage->type === 'lost' && in_array($stage->type, ['won', 'lost'], true)) {
                $this->fail('stage_id', 'Reopen a lost lead to a nonterminal stage first.');
            }
            $this->rules->enforce($lead, $stage, $this->role($org, $actor));
            $reason = null;
            if ($stage->type === 'lost') {
                $reason = $pipeline->reasons()->where('active', true)->find(isset($data['lost_reason_id']) ? (int) $data['lost_reason_id'] : null);
                if (! $reason) {
                    $this->fail('lost_reason_id', 'Choose an active lost reason in the destination pipeline.');
                }
            }
            $from = $lead->stage;
            $previousPipelineId = $lead->pipeline_id;
            $lead->update(['pipeline_id' => $pipeline->id, 'current_stage_id' => $stage->id, 'lost_reason_id' => $reason?->id, 'stage_changed_at' => now()]);
            $this->autoAssign->handle($org, $lead, $stage);
            $this->record($org, $actor, $lead, $from, $stage, $reason, $data['notes'] ?? 'Transferred between pipelines.');
            $this->audit->handle($org, $actor, 'crm.lead.transferred', $lead, ['from_pipeline_id' => $previousPipelineId, 'to_pipeline_id' => $pipeline->id, 'from_stage_id' => $from->id, 'to_stage_id' => $stage->id]);
        });
    }

    public function convert(Organization $org, User $actor, CrmLead $lead): void
    {
        DB::transaction(function () use ($org, $actor, $lead): void {
            $lead = $this->lockLead($org, $actor, $lead);
            abort_if($lead->converted_at !== null, 422, 'This lead has already been converted.');
            if ($lead->stage->type === 'lost') {
                $this->fail('stage_id', 'Reopen this lost lead before converting it.');
            }
            $pipeline = Pipeline::where('organization_id', $org->id)->where('active', true)->find($lead->pipeline_id);
            if (! $pipeline) {
                $this->fail('stage_id', 'Activate this pipeline before converting leads.');
            }
            $won = $lead->stage->type === 'won' && $lead->stage->active ? $lead->stage : $pipeline->stages()->where('type', 'won')->where('active', true)->first();
            if (! $won) {
                $this->fail('stage_id', 'Configure an active Won stage before converting leads.');
            }
            $this->rules->enforce($lead, $won, $this->role($org, $actor), $lead->current_stage_id !== $won->id);
            if ($lead->current_stage_id !== $won->id) {
                $from = $lead->stage;
                $lead->update(['current_stage_id' => $won->id, 'lost_reason_id' => null, 'stage_changed_at' => now()]);
                $this->record($org, $actor, $lead, $from, $won, null, 'Explicit customer conversion.');
            }
            $account = $lead->company ? CrmAccount::firstOrCreate(['organization_id' => $org->id, 'name' => $lead->company]) : null;
            $contact = CrmContact::create(['organization_id' => $org->id, 'account_id' => $account?->id, ...$lead->only('first_name', 'last_name', 'email', 'phone')]);
            $lead->update(['status' => 'converted', 'converted_at' => now(), 'converted_contact_id' => $contact->id, 'converted_account_id' => $account?->id]);
            $this->audit->handle($org, $actor, 'crm.lead.converted', $lead, ['contact_id' => $contact->id, 'account_id' => $account?->id]);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateDetails(Organization $org, User $actor, CrmLead $lead, array $data): void
    {
        DB::transaction(function () use ($org, $actor, $lead, $data): void {
            $lead = $this->lockLead($org, $actor, $lead);
            if ($lead->converted_at) {
                $this->fail('first_name', 'Converted lead details cannot be changed.');
            }
            $custom = $data['custom_fields'] ?? [];
            unset($data['custom_fields']);
            $before = $lead->only(array_keys($data));
            $lead->update($data);
            $this->fields->writeValues($org, $actor, $lead, $custom, false);
            $changed = array_keys(array_filter($data, fn ($value, $key) => $before[$key] !== $value, ARRAY_FILTER_USE_BOTH));
            $this->audit->handle($org, $actor, 'crm.lead.details_updated', $lead, ['changed_fields' => $changed]);
        });
    }

    private function role(Organization $org, User $actor): ?string
    {
        return $actor->organizations()->whereKey($org->id)->value('organization_user.role');
    }

    private function lockLead(Organization $org, User $actor, CrmLead $lead): CrmLead
    {
        Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();

        $locked = CrmLead::where('organization_id', $org->id)->lockForUpdate()->findOrFail($lead->id);
        abort_unless($this->visibility->canSeeLead($org, $actor, $locked->assigned_to), 404);

        return $locked;
    }

    private function record(Organization $org, ?User $actor, CrmLead $lead, ?PipelineStage $from, PipelineStage $to, ?LostReason $reason, ?string $notes): void
    {
        $history = LeadStageHistory::create(['organization_id' => $org->id, 'lead_id' => $lead->id, 'pipeline_id' => $lead->pipeline_id, 'from_stage_id' => $from?->id, 'to_stage_id' => $to->id, 'lost_reason_id' => $reason?->id, 'changed_by' => $actor?->id, 'changed_at' => now(), 'notes' => $notes, 'snapshot' => ['pipeline' => $to->pipeline->name, 'from_pipeline' => $from?->pipeline?->name, 'from' => $from?->name, 'from_type' => $from?->type, 'to' => $to->name, 'to_type' => $to->type, 'lost_reason' => $reason?->name, 'assigned_to' => $lead->assigned_to, 'notify_assignee_on_entry' => (bool) $to->notify_assignee_on_entry, 'follow_up_due_days' => $to->follow_up_due_days]]);
        $this->audit->handle($org, $actor, 'crm.lead.stage_changed', $lead, $history->snapshot);
        event(new LeadStageChanged($history));
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
