<?php

namespace App\Http\Controllers;

use App\Domain\Crm\Queries\LocalMatchmaker;
use App\Domain\Crm\Services\LeadVisibility;
use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Models\CrmLead;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class MatchmakerController extends Controller
{
    public function index(Request $request, LeadVisibility $visibility): Response
    {
        $org = $request->user()->currentOrganization;
        abort_unless($org !== null, 404);
        $this->authorize('viewCrm', $org);
        $leads = $visibility->scope(CrmLead::where('organization_id', $org->id), $org, $request->user())
            ->whereNotIn('status', ['converted', 'lost'])->orderBy('last_name')->limit(100)->get(['id', 'first_name', 'last_name']);

        return Inertia::render('crm/Matchmaker', ['leads' => $leads, 'mode' => 'local_rules', 'canManage' => $request->user()->can('manageCrm', $org)]);
    }

    public function show(Request $request, int $lead, LocalMatchmaker $matchmaker): Response
    {
        $org = $request->user()->currentOrganization;
        abort_unless($org !== null, 404);
        $matches = $matchmaker->forLead($org, $request->user(), $lead);

        return Inertia::render('crm/MatchmakerLead', [
            'leadId' => $lead,
            'preference' => DB::table('lead_search_preferences')->where('organization_id', $org->id)->where('lead_id', $lead)->first(),
            'matches' => $matches, 'mode' => 'local_rules',
        ]);
    }

    public function preference(Request $request, int $lead, LeadVisibility $visibility, RecordOrganizationAuditLog $audit): RedirectResponse
    {
        $org = $request->user()->currentOrganization;
        abort_unless($org !== null, 404);
        $this->authorize('manageCrm', $org);
        $record = CrmLead::where('organization_id', $org->id)->whereKey($lead)->first();
        abort_unless($record !== null && $visibility->canSeeLead($org, $request->user(), $record->assigned_to), 404);
        $input = $request->validate([
            'purpose' => ['required', 'in:sale,rent'], 'city' => ['nullable', 'string', 'max:255'],
            'property_type' => ['nullable', 'string', 'max:40'],
            'min_price_aed' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'max_price_aed' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
        ]);
        if (isset($input['min_price_aed'], $input['max_price_aed']) && (float) $input['max_price_aed'] < (float) $input['min_price_aed']) {
            throw ValidationException::withMessages(['max_price_aed' => 'Maximum price must be at least the minimum price.']);
        }
        DB::transaction(function () use ($org, $request, $lead, $input, $audit): void {
            DB::table('lead_search_preferences')->updateOrInsert(['lead_id' => $lead], [
                'organization_id' => $org->id, 'updated_by' => $request->user()->id,
                'purpose' => $input['purpose'], 'city' => $input['city'] ?? null,
                'property_type' => $input['property_type'] ?? null,
                'min_price_aed' => $input['min_price_aed'] ?? null, 'max_price_aed' => $input['max_price_aed'] ?? null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $audit->handle($org, $request->user(), 'crm.matchmaker.preference_saved', $org, ['lead_id' => $lead]);
        });

        return back();
    }
}
