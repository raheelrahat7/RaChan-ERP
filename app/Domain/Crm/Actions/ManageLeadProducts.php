<?php

namespace App\Domain\Crm\Actions;

use App\Domain\Crm\Services\CrmEditPermission;
use App\Domain\Crm\Services\LeadVisibility;
use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Domain\Workflows\Models\WorkflowRecord;
use App\Domain\Workflows\Services\WorkflowAccess;
use App\Domain\Workflows\Services\WorkflowOverview;
use App\Models\CrmLead;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ManageLeadProducts
{
    /** @return array<string, mixed> */
    public function index(Organization $org, User $actor, int $leadId): array
    {
        $lead = $this->lead($org, $actor, $leadId);
        $products = DB::table('crm_lead_products as lines')->join('organization_crm_settings as product', 'product.id', '=', 'lines.product_id')
            ->where('lines.organization_id', $org->id)->where('lines.lead_id', $lead->id)
            ->get(['lines.id', 'lines.product_id', 'product.code', 'product.name', 'lines.quantity', 'lines.unit_price', 'lines.currency']);
        $estimates = WorkflowRecord::where('organization_id', $org->id)->where('lead_id', $lead->id)
            ->whereHas('pipeline', fn ($query) => $query->where('kind', 'estimate'))
            ->with(['pipeline', 'stage'])->latest('id')->get()
            ->filter(fn ($record) => app(WorkflowAccess::class)->allows($org, $actor, $record->pipeline))->map(fn (WorkflowRecord $record) => app(WorkflowOverview::class)->serialize($org, $actor, $record))->values();

        return ['products' => $products, 'estimates' => $estimates];
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function save(Organization $org, User $actor, int $leadId, array $input, ?int $id = null): array
    {
        $lead = $this->lead($org, $actor, $leadId, true);
        $data = Validator::make($input, ['product_id' => ['required', 'integer'], 'quantity' => ['required', 'numeric', 'gt:0', 'max:999999999999'], 'unit_price' => ['required', 'numeric', 'min:0', 'max:999999999999.99'], 'currency' => ['required', 'regex:/^[A-Z]{3}$/']])->validate();

        return DB::transaction(function () use ($org, $actor, $lead, $data, $id): array {
            Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
            $product = DB::table('organization_crm_settings')->where('organization_id', $org->id)->where('kind', 'products')->where('active', true)->where('id', $data['product_id'])->first();
            if (! $product) {
                throw ValidationException::withMessages(['product_id' => 'Select an active organization product.']);
            }
            $existing = $id ? DB::table('crm_lead_products')->where('organization_id', $org->id)->where('lead_id', $lead->id)->where('id', $id)->first() : null;
            abort_if($id && ! $existing, 404);
            if ($existing && $existing->product_id !== $data['product_id'] || DB::table('crm_lead_products')->where('lead_id', $lead->id)->where('product_id', $data['product_id'])->where('id', '!=', $id ?? 0)->exists()) {
                throw ValidationException::withMessages(['product_id' => 'This product is already linked to the lead.']);
            }
            $row = [...$data, 'organization_id' => $org->id, 'lead_id' => $lead->id, 'updated_at' => now()];
            if ($id) {
                DB::table('crm_lead_products')->where('id', $id)->update($row);
            } else {
                $id = DB::table('crm_lead_products')->insertGetId([...$row, 'created_at' => now()]);
            }
            app(RecordOrganizationAuditLog::class)->handle($org, $actor, 'crm.lead.product_saved', $lead, ['product_id' => $data['product_id'], 'line_id' => $id]);

            return ['id' => $id, ...$row];
        });
    }

    public function remove(Organization $org, User $actor, int $leadId, int $id): void
    {
        $lead = $this->lead($org, $actor, $leadId, true);
        DB::transaction(function () use ($org, $actor, $lead, $id): void {
            $line = DB::table('crm_lead_products')->where('organization_id', $org->id)->where('lead_id', $lead->id)->where('id', $id)->lockForUpdate()->first();
            abort_unless($line !== null, 404);
            DB::table('crm_lead_products')->where('id', $id)->delete();
            app(RecordOrganizationAuditLog::class)->handle($org, $actor, 'crm.lead.product_removed', $lead, ['product_id' => $line->product_id, 'line_id' => $id]);
        });
    }

    private function lead(Organization $org, User $actor, int $id, bool $write = false): CrmLead
    {
        abort_unless($actor->can('viewCrm', $org), 403);
        $lead = CrmLead::where('organization_id', $org->id)->findOrFail($id);
        abort_unless(app(LeadVisibility::class)->canSeeLead($org, $actor, $lead->assigned_to), 404);
        if ($write) {
            abort_unless(app(CrmEditPermission::class)->granted($org, $actor), 403);
        }

        return $lead;
    }
}
