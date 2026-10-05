<?php

namespace App\Domain\Crm\Actions;

use App\Domain\Crm\Models\Deal;
use App\Domain\Crm\Services\DealAccess;
use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Models\CrmActivity;
use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ManageDealActivities
{
    public function __construct(private DealAccess $access, private RecordOrganizationAuditLog $audit) {}

    /** @param array<string, mixed> $input */
    public function save(Organization $org, User $actor, Deal $deal, array $input, ?int $id = null): CrmActivity
    {
        $data = Validator::make($input, ['expected_version' => ['required', 'integer', 'min:1'], 'type' => ['required', 'in:call,email,meeting,task,note'], 'notes' => ['nullable', 'string', 'max:5000'], 'due_at' => ['nullable', 'date'], 'completed' => ['sometimes', 'boolean']])->validate();

        return DB::transaction(function () use ($org, $actor, $deal, $data, $id): CrmActivity {
            Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
            $deal = $this->access->query($org, $actor)->lockForUpdate()->findOrFail($deal->id);
            abort_unless($this->access->allows($org, $actor, $deal->pipeline, 'edit', $deal->assigned_to), 403);
            if ($deal->version !== (int) $data['expected_version']) {
                throw ValidationException::withMessages(['expected_version' => 'This deal changed. Refresh before saving.']);
            }
            if ($deal->closed_at) {
                throw ValidationException::withMessages(['activity' => 'Reopen a closed deal before changing activities.']);
            }
            $activity = $id ? $deal->activities()->where('organization_id', $org->id)->lockForUpdate()->findOrFail($id) : $deal->activities()->make(['organization_id' => $org->id, 'created_by' => $actor->id]);
            if ($activity->completed_at) {
                throw ValidationException::withMessages(['activity' => 'Completed activities are read-only.']);
            }
            $activity->fill(['type' => $data['type'], 'notes' => $data['notes'] ?? null, 'due_at' => empty($data['due_at']) ? null : CarbonImmutable::parse($data['due_at'], $org->timezone)->utc(), 'completed_at' => ($data['completed'] ?? false) ? now() : null])->save();
            $deal->increment('version');
            $this->audit->handle($org, $actor, $id ? 'crm.deal.activity_updated' : 'crm.deal.activity_created', $deal, ['activity_id' => $activity->id, 'completed' => $activity->completed_at !== null]);

            return $activity;
        });
    }
}
