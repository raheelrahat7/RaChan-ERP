<?php

namespace App\Domain\Compliance\Actions;

use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Domain\Identity\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ManageGaim
{
    public function __construct(private RecordOrganizationAuditLog $audit) {}

    public function manages(Organization $org, User $actor): bool
    {
        return $actor->hasOrganizationRole($org, OrganizationRole::Owner)
            || $actor->hasOrganizationRole($org, OrganizationRole::Administrator);
    }

    /** @param array<string,mixed> $input */
    public function rule(Organization $org, User $actor, array $input): int
    {
        abort_unless($this->manages($org, $actor), 403);

        return DB::transaction(function () use ($org, $actor, $input): int {
            Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
            if (DB::table('gaim_routing_rules')->where('organization_id', $org->id)->where('emirate', $input['emirate'])
                ->where('event', $input['event'])->where('authority', $input['authority'])->where('form_code', $input['form_code'])->exists()) {
                throw ValidationException::withMessages(['form_code' => 'This authority form is already routed for the event and emirate.']);
            }
            $id = DB::table('gaim_routing_rules')->insertGetId([
                'organization_id' => $org->id, 'created_by' => $actor->id,
                'emirate' => $input['emirate'], 'event' => $input['event'],
                'authority' => $input['authority'], 'form_code' => $input['form_code'], 'form_name' => $input['form_name'],
                'required' => $input['required'] ?? true, 'active' => true,
                'effective_from' => $input['effective_from'] ?? null, 'notes' => $input['notes'] ?? null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit->handle($org, $actor, 'gaim.rule.created', $org, ['rule_id' => $id]);

            return $id;
        });
    }

    /** @param array<string,mixed> $input */
    public function record(Organization $org, User $actor, array $input): int
    {
        abort_unless($this->manages($org, $actor), 403);
        $rule = DB::table('gaim_routing_rules')->where('organization_id', $org->id)->where('id', $input['routing_rule_id'])->where('active', true)->first();
        abort_unless($rule !== null, 404);
        $table = match ($input['subject_type']) {
            'listing' => 'listings', 'lease' => 'leases', 'sale_contract' => 'sales_contracts', 'offplan_deal' => 'offplan_deals',
            default => null,
        };
        $compatible = match ($rule->event) {
            'listing_publish' => $input['subject_type'] === 'listing',
            'lease_sign', 'lease_renew' => $input['subject_type'] === 'lease',
            'sale_transfer' => $input['subject_type'] === 'sale_contract',
            'offplan_sale' => $input['subject_type'] === 'offplan_deal',
            default => false,
        };
        if (! $compatible || $table === null || ! DB::table($table)->where('organization_id', $org->id)->where('id', $input['subject_id'])->exists()) {
            throw ValidationException::withMessages(['subject_id' => 'Choose a subject in this organization matching the routing event.']);
        }

        return DB::transaction(function () use ($org, $actor, $input): int {
            Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
            if (DB::table('gaim_compliance_records')->where('organization_id', $org->id)->where('routing_rule_id', $input['routing_rule_id'])
                ->where('subject_type', $input['subject_type'])->where('subject_id', $input['subject_id'])->exists()) {
                throw ValidationException::withMessages(['routing_rule_id' => 'This subject already has a record for the routing rule.']);
            }
            $id = DB::table('gaim_compliance_records')->insertGetId([
                'organization_id' => $org->id, 'routing_rule_id' => $input['routing_rule_id'], 'created_by' => $actor->id,
                'subject_type' => $input['subject_type'], 'subject_id' => $input['subject_id'],
                'status' => 'required', 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->event($org, $actor, $id, null, 'required', null);
            $this->audit->handle($org, $actor, 'gaim.record.created', $org, ['record_id' => $id]);

            return $id;
        });
    }

    /** @param array{emirate:string,event:string,subject_type:string,subject_id:int} $input */
    public function sync(Organization $org, User $actor, array $input): int
    {
        abort_unless($this->manages($org, $actor), 403);
        $expectedType = match ($input['event']) {
            'listing_publish' => 'listing',
            'lease_sign', 'lease_renew' => 'lease',
            'sale_transfer' => 'sale_contract',
            'offplan_sale' => 'offplan_deal',
            default => null,
        };
        $table = match ($expectedType) {
            'listing' => 'listings',
            'lease' => 'leases',
            'sale_contract' => 'sales_contracts',
            'offplan_deal' => 'offplan_deals',
            default => null,
        };
        if ($table === null || $input['subject_type'] !== $expectedType || ! DB::table($table)->where('organization_id', $org->id)->where('id', $input['subject_id'])->exists()) {
            throw ValidationException::withMessages(['subject_id' => 'Choose a subject in this organization matching the routing event.']);
        }

        return DB::transaction(function () use ($org, $actor, $input): int {
            Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
            $rules = DB::table('gaim_routing_rules')->where('organization_id', $org->id)
                ->where('emirate', $input['emirate'])->where('event', $input['event'])
                ->where('required', true)->where('active', true)
                ->where(fn ($query) => $query->whereNull('effective_from')->orWhere('effective_from', '<=', now()->toDateString()))
                ->get(['id']);
            $created = 0;
            foreach ($rules as $rule) {
                $exists = DB::table('gaim_compliance_records')->where('organization_id', $org->id)
                    ->where('routing_rule_id', $rule->id)->where('subject_type', $input['subject_type'])
                    ->where('subject_id', $input['subject_id'])->exists();
                if ($exists) {
                    continue;
                }
                $id = DB::table('gaim_compliance_records')->insertGetId([
                    'organization_id' => $org->id, 'routing_rule_id' => $rule->id, 'created_by' => $actor->id,
                    'subject_type' => $input['subject_type'], 'subject_id' => $input['subject_id'],
                    'status' => 'required', 'created_at' => now(), 'updated_at' => now(),
                ]);
                $this->event($org, $actor, $id, null, 'required', null);
                $created++;
            }
            $this->audit->handle($org, $actor, 'gaim.records.synced', $org, [
                'emirate' => $input['emirate'], 'event' => $input['event'], 'subject_type' => $input['subject_type'],
                'subject_id' => $input['subject_id'], 'created' => $created,
            ]);

            return $created;
        });
    }

    /** @param array<string,mixed> $input */
    public function status(Organization $org, User $actor, int $id, array $input): void
    {
        abort_unless($this->manages($org, $actor), 403);
        DB::transaction(function () use ($org, $actor, $id, $input): void {
            $record = DB::table('gaim_compliance_records')->where('organization_id', $org->id)->where('id', $id)->lockForUpdate()->first();
            abort_unless($record !== null, 404);
            $allowed = match ($record->status) {
                'required' => ['preparing', 'not_applicable'],
                'preparing' => ['submitted', 'not_applicable'],
                'submitted' => ['approved', 'rejected'],
                'rejected', 'expired' => ['preparing'],
                'approved' => ['expired'],
                default => [],
            };
            abort_unless(in_array($input['status'], $allowed, true), 422, 'This compliance status transition is not allowed.');
            if (in_array($input['status'], ['submitted', 'approved'], true) && blank($input['authority_reference'] ?? $record->authority_reference)) {
                throw ValidationException::withMessages(['authority_reference' => 'Record the authority reference before submitting or approving.']);
            }
            if (in_array($input['status'], ['rejected', 'not_applicable', 'expired'], true) && blank($input['reason'] ?? null)) {
                throw ValidationException::withMessages(['reason' => 'A reason is required for this status.']);
            }
            DB::table('gaim_compliance_records')->where('id', $id)->update([
                'status' => $input['status'], 'authority_reference' => $input['authority_reference'] ?? $record->authority_reference,
                'expires_on' => $input['expires_on'] ?? $record->expires_on,
                'reason' => $input['reason'] ?? null,
                'submitted_at' => $input['status'] === 'submitted' ? now() : $record->submitted_at,
                'decided_at' => in_array($input['status'], ['approved', 'rejected'], true) ? now() : $record->decided_at,
                'updated_at' => now(),
            ]);
            $this->event($org, $actor, $id, $record->status, $input['status'], $input['reason'] ?? null);
            $this->audit->handle($org, $actor, 'gaim.record.status_changed', $org, ['record_id' => $id, 'from' => $record->status, 'to' => $input['status']]);
        });
    }

    /** @param array<string,mixed> $input */
    public function bulletin(Organization $org, User $actor, array $input): int
    {
        abort_unless($this->manages($org, $actor), 403);

        return DB::transaction(function () use ($org, $actor, $input): int {
            $id = DB::table('gaim_bulletins')->insertGetId([
                'organization_id' => $org->id, 'created_by' => $actor->id,
                'authority' => $input['authority'], 'title' => $input['title'], 'body' => $input['body'],
                'affected_forms' => json_encode($input['affected_forms'] ?? [], JSON_THROW_ON_ERROR),
                'status' => 'draft', 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit->handle($org, $actor, 'gaim.bulletin.drafted', $org, ['bulletin_id' => $id]);

            return $id;
        });
    }

    public function publishBulletin(Organization $org, User $actor, int $id): void
    {
        abort_unless($this->manages($org, $actor), 403);
        DB::transaction(function () use ($org, $actor, $id): void {
            $bulletin = DB::table('gaim_bulletins')->where('organization_id', $org->id)->where('id', $id)->lockForUpdate()->first();
            abort_unless($bulletin !== null && $bulletin->status === 'draft', 422);
            DB::table('gaim_bulletins')->where('id', $id)->update(['status' => 'published', 'published_by' => $actor->id, 'published_at' => now(), 'updated_at' => now()]);
            $this->audit->handle($org, $actor, 'gaim.bulletin.published', $org, ['bulletin_id' => $id]);
        });
    }

    private function event(Organization $org, User $actor, int $id, ?string $from, string $to, ?string $reason): void
    {
        DB::table('gaim_record_events')->insert([
            'organization_id' => $org->id, 'record_id' => $id, 'actor_id' => $actor->id,
            'from_status' => $from, 'to_status' => $to, 'reason' => $reason, 'occurred_at' => now(),
        ]);
    }
}
