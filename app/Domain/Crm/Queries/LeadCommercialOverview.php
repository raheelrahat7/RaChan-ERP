<?php

namespace App\Domain\Crm\Queries;

use App\Domain\Crm\Models\LeadCommercialRecord;
use App\Domain\Crm\Services\DealAccess;
use App\Domain\Workflows\Models\WorkflowRecord;
use App\Domain\Workflows\Services\WorkflowAccess;
use App\Models\CrmLead;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class LeadCommercialOverview
{
    public function __construct(private DealAccess $deals, private WorkflowAccess $workflows) {}

    /** @return array<string, mixed> */
    public function records(Organization $org, User $actor, CrmLead $lead): array
    {
        $editable = $actor->can('manageCrm', $org);
        $linkedDeal = $this->deals->linkedLeadDeal($org, $actor, $lead);
        $records = LeadCommercialRecord::where('organization_id', $org->id)->where('lead_id', $lead->id)
            ->orderByDesc('id')->paginate(30);
        $records->through(fn (LeadCommercialRecord $record) => $this->serialize($record, $editable, $linkedDeal));

        return ['records' => $records, 'linked_deal' => $linkedDeal, 'permissions' => ['view' => true, 'create' => $editable && $lead->converted_at === null]];
    }

    /** @param array<string, mixed>|null $linkedDeal
     * @return array<string, mixed> */
    public function serialize(LeadCommercialRecord $record, bool $editable, ?array $linkedDeal): array
    {
        return [...$record->only('id', 'lead_id', 'kind', 'reference', 'title', 'party_name', 'status', 'amount', 'currency', 'submitted_on', 'signed_on', 'notes', 'deal_id', 'created_at', 'updated_at', 'version'),
            'submitted_on' => $record->submitted_on?->format('Y-m-d'), 'signed_on' => $record->signed_on?->format('Y-m-d'),
            'deal_id' => $record->deal_id && $linkedDeal && $linkedDeal['id'] === $record->deal_id ? $record->deal_id : null,
            'permissions' => ['view' => true, 'edit' => $editable]];
    }

    /** @return array<string, mixed> */
    public function accounting(Organization $org, User $actor, CrmLead $lead): array
    {
        $allowed = $actor->can('viewFinance', $org);
        if (! $allowed) {
            return ['documents' => [], 'permissions' => ['view' => false]];
        }
        $documents = [];
        $invoiceIds = [];
        $estimates = WorkflowRecord::where('organization_id', $org->id)->where('lead_id', $lead->id)
            ->whereHas('pipeline', fn ($query) => $query->where('kind', 'estimate'))
            ->with(['pipeline', 'stage'])->orderByDesc('id')->get();
        foreach ($estimates as $estimate) {
            if (! $this->workflows->allows($org, $actor, $estimate->pipeline)) {
                continue;
            }
            $documents[] = [
                'kind' => 'estimate', 'id' => $estimate->id, 'reference' => $estimate->reference, 'title' => $estimate->title,
                'status' => $estimate->stage->name, 'amount' => $estimate->details['total'] ?? null,
                'vat' => null, 'total' => $estimate->details['total'] ?? null,
                'currency' => $estimate->details['currency'] ?? null, 'version' => $estimate->version,
                'permissions' => ['view' => true, 'edit' => $this->workflows->allows($org, $actor, $estimate->pipeline, true)],
            ];
            if ($estimate->invoice_id) {
                $invoiceIds[] = $estimate->invoice_id;
            }
        }
        $deal = $lead->deal;
        if ($deal && $this->deals->allows($org, $actor, $deal->pipeline, 'read', $deal->assigned_to)
            && $this->deals->allows($org, $actor, $deal->pipeline, 'amount', $deal->assigned_to)) {
            $invoiceIds = [...$invoiceIds, ...DB::table('crm_deal_financial_links')->where('organization_id', $org->id)->where('deal_id', $deal->id)->whereNotNull('invoice_id')->pluck('invoice_id')->all()];
        }
        foreach (Invoice::where('organization_id', $org->id)->whereIn('id', array_unique($invoiceIds))->orderByDesc('id')->get() as $invoice) {
            $documents[] = [
                'kind' => 'invoice', 'id' => $invoice->id, 'reference' => $invoice->reference, 'title' => null,
                'status' => $invoice->status, 'amount' => $invoice->subtotal, 'vat' => $invoice->vat_amount,
                'total' => $invoice->total, 'currency' => $invoice->currency, 'version' => null,
                'permissions' => ['view' => true, 'edit' => false],
            ];
        }

        return ['documents' => $documents, 'permissions' => ['view' => true]];
    }
}
