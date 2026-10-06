<?php

namespace App\Http\Controllers;

use App\Domain\Crm\Actions\ManageCustomFields;
use App\Domain\Crm\Models\CustomFieldValue;
use App\Domain\Crm\Services\CrmEditPermission;
use App\Domain\Crm\Services\LeadVisibility;
use App\Models\CrmLead;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class QualifiedLeadController extends Controller
{
    public function __invoke(Request $request, LeadVisibility $visibility, ManageCustomFields $fields): JsonResponse
    {
        $org = $request->user()->currentOrganization ?? abort(404);
        $this->authorize('viewCrm', $org);
        $filters = $request->validate(['q' => ['sometimes', 'string', 'max:150'], 'per_page' => ['sometimes', 'integer', 'between:1,100']]);
        $q = $filters['q'] ?? null;
        $query = CrmLead::where('organization_id', $org->id)->whereDoesntHave('deal')
            ->whereHas('pipeline', fn ($pipeline) => $pipeline->where('active', true))
            ->whereHas('stage', fn ($stage) => $stage->where('active', true)->where('type', 'won'));
        $visibility->scope($query, $org, $request->user());
        $leads = $query->with(['stage:id,name', 'assignee:id,name'])->when($q, fn ($query) => $query->where(fn ($query) => $query->where('first_name', 'like', "%{$q}%")->orWhere('last_name', 'like', "%{$q}%")->orWhere('email', 'like', "%{$q}%")->orWhere('company', 'like', "%{$q}%")))
            ->latest('id')->paginate($filters['per_page'] ?? 25)->withQueryString();
        $amountField = collect($fields->visible($org, $request->user()))->first(fn ($field) => $field->type === 'currency');
        $amounts = $amountField ? CustomFieldValue::where('organization_id', $org->id)->where('field_id', $amountField->id)->whereIn('lead_id', $leads->getCollection()->pluck('id'))->pluck('number_value', 'lead_id') : collect();
        $leads->through(fn (CrmLead $lead) => [
            'id' => $lead->id, 'name' => trim($lead->first_name.' '.$lead->last_name),
            'stage' => $lead->stage?->only('id', 'name'), 'assignee' => $lead->assignee?->only('id', 'name'),
            'amount' => $amountField ? ($amounts[$lead->id] ?? null) : null, 'permissions' => ['read' => true, 'edit' => app(CrmEditPermission::class)->granted($org, $request->user())],
        ]);

        return response()->json(['leads' => $leads]);
    }
}
