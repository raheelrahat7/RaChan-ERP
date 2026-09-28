<?php

namespace App\Http\Controllers;

use App\Domain\Compliance\Actions\ManageGaim;
use App\Models\Organization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class GaimController extends Controller
{
    public function index(Request $request): Response
    {
        $org = $this->org($request);
        $this->authorize('viewInventory', $org);
        $records = DB::table('gaim_compliance_records as record')->join('gaim_routing_rules as rule', 'rule.id', '=', 'record.routing_rule_id')
            ->where('record.organization_id', $org->id)->where('rule.organization_id', $org->id)
            ->orderBy('rule.emirate')->orderByDesc('record.id')
            ->get(['record.id', 'record.subject_type', 'record.subject_id', 'record.status', 'record.authority_reference', 'record.expires_on',
                'rule.emirate', 'rule.authority', 'rule.form_code', 'rule.form_name']);
        $summary = array_map(function (string $emirate) use ($records): array {
            $group = $records->where('emirate', $emirate);

            return ['emirate' => $emirate, 'total_records' => $group->count(),
                'approved' => $group->where('status', 'approved')->count(),
                'pending' => $group->whereIn('status', ['required', 'preparing', 'submitted'])->count(),
                'rejected_or_expired' => $group->whereIn('status', ['rejected', 'expired'])->count(),
                'compliance_pct' => $group->count() > 0 ? round($group->where('status', 'approved')->count() / $group->count() * 100, 2) : null];
        }, config('gaim.emirates'));

        return Inertia::render('compliance/Gaim', [
            'summary' => $summary, 'records' => $records,
            'routingRules' => DB::table('gaim_routing_rules')->where('organization_id', $org->id)->orderBy('emirate')->orderBy('authority')->get(),
            'canManage' => app(ManageGaim::class)->manages($org, $request->user()),
            'statusOptions' => config('gaim.statuses'), 'eventOptions' => config('gaim.events'),
        ]);
    }

    public function bulletins(Request $request): Response
    {
        $org = $this->org($request);
        $this->authorize('viewInventory', $org);
        $manage = app(ManageGaim::class)->manages($org, $request->user());

        return Inertia::render('compliance/GaimBulletins', [
            'bulletins' => DB::table('gaim_bulletins')->where('organization_id', $org->id)
                ->when(! $manage, fn ($query) => $query->where('status', 'published'))
                ->orderByDesc('id')->paginate(30),
            'canManage' => $manage,
        ]);
    }

    public function rule(Request $request, ManageGaim $gaim): RedirectResponse
    {
        $org = $this->org($request);
        $gaim->rule($org, $request->user(), $request->validate([
            'emirate' => ['required', Rule::in(config('gaim.emirates'))],
            'event' => ['required', Rule::in(config('gaim.events'))],
            'authority' => ['required', 'string', 'max:100'], 'form_code' => ['required', 'string', 'max:100'],
            'form_name' => ['required', 'string', 'max:255'], 'required' => ['sometimes', 'boolean'],
            'effective_from' => ['nullable', 'date_format:Y-m-d'], 'notes' => ['nullable', 'string', 'max:3000'],
        ]));

        return back();
    }

    public function record(Request $request, ManageGaim $gaim): RedirectResponse
    {
        $org = $this->org($request);
        $gaim->record($org, $request->user(), $request->validate([
            'routing_rule_id' => ['required', 'integer'], 'subject_type' => ['required', 'in:listing,lease,sale_contract,offplan_deal'],
            'subject_id' => ['required', 'integer', 'min:1'],
        ]));

        return back();
    }

    public function sync(Request $request, ManageGaim $gaim): RedirectResponse
    {
        $org = $this->org($request);
        $gaim->sync($org, $request->user(), $request->validate([
            'emirate' => ['required', Rule::in(config('gaim.emirates'))],
            'event' => ['required', Rule::in(config('gaim.events'))],
            'subject_type' => ['required', 'in:listing,lease,sale_contract,offplan_deal'],
            'subject_id' => ['required', 'integer', 'min:1'],
        ]));

        return back();
    }

    public function status(Request $request, int $record, ManageGaim $gaim): RedirectResponse
    {
        $org = $this->org($request);
        $gaim->status($org, $request->user(), $record, $request->validate([
            'status' => ['required', Rule::in(config('gaim.statuses'))],
            'authority_reference' => ['nullable', 'string', 'max:150'], 'expires_on' => ['nullable', 'date_format:Y-m-d'],
            'reason' => ['nullable', 'string', 'max:3000'],
        ]));

        return back();
    }

    public function bulletin(Request $request, ManageGaim $gaim): RedirectResponse
    {
        $org = $this->org($request);
        $gaim->bulletin($org, $request->user(), $request->validate([
            'authority' => ['required', 'string', 'max:100'], 'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:10000'], 'affected_forms' => ['sometimes', 'array', 'max:30'],
            'affected_forms.*' => ['required', 'string', 'max:100'],
        ]));

        return back();
    }

    public function publish(Request $request, int $bulletin, ManageGaim $gaim): RedirectResponse
    {
        $org = $this->org($request);
        $gaim->publishBulletin($org, $request->user(), $bulletin);

        return back();
    }

    private function org(Request $request): Organization
    {
        $org = $request->user()->currentOrganization;
        abort_unless($org !== null && $request->user()->belongsToOrganization($org), 404);

        return $org;
    }
}
