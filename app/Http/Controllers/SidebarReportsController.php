<?php

namespace App\Http\Controllers;

use App\Domain\Crm\Queries\BrokerPerformance;
use App\Domain\Crm\Services\LeadVisibility;
use App\Domain\Leasing\Queries\ChequeRegister;
use App\Models\CrmLead;
use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class SidebarReportsController extends Controller
{
    public function brokerPerformance(Request $request, BrokerPerformance $performance): Response
    {
        $org = $this->org($request);
        $this->authorize('viewCrm', $org);

        return Inertia::render('crm/BrokerPerformance', ['brokers' => $performance->for($org, $request->user())]);
    }

    public function pdc(Request $request, ChequeRegister $cheques): Response
    {
        $org = $this->org($request);
        $this->authorize('viewTransactions', $org);
        $filters = $request->validate(['status' => ['nullable', 'in:scheduled,deposited,cleared,bounced,replaced'],
            'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from']]);

        return Inertia::render('transactions/PdcRegister', [
            'cheques' => $cheques->for($org, $filters['status'] ?? null, $filters['from'] ?? null, $filters['to'] ?? null),
            'filters' => $filters, 'canManage' => $request->user()->can('manageTransactions', $org),
            'actionRoutePattern' => '/lease-compliance/cheques/{id}/{action}',
        ]);
    }

    public function reports(Request $request): Response
    {
        $org = $this->org($request);
        $this->authorize('view', $org);
        $items = [];
        foreach ([
            ['CRM pipeline', 'crm.pipeline-report', 'viewCrm'],
            ['Property profitability', 'reports.property-profitability', 'viewFinance'],
            ['Owner statements', 'reports.owner-statements.index', 'viewFinance'],
            ['Operations reports', 'operations.reports', 'viewOperations'],
            ['Journal register', 'accounting.journal-register', 'viewFinance'],
            ['Operating budgets', 'accounting.budgets', 'viewFinance'],
        ] as [$label, $routeName, $ability]) {
            if ($request->user()->can($ability, $org)) {
                $items[] = ['label' => $label, 'href' => route($routeName)];
            }
        }

        return Inertia::render('reports/Index', ['reports' => $items]);
    }

    public function leadGateway(Request $request, LeadVisibility $visibility): Response
    {
        $org = $this->org($request);
        $this->authorize('viewCrm', $org);
        $leads = $visibility->scope(CrmLead::where('organization_id', $org->id), $org, $request->user());

        return Inertia::render('crm/LeadGateway', [
            'sources' => (clone $leads)->selectRaw('COALESCE(NULLIF(source, ""), "Unknown") AS source_name, COUNT(*) AS total')
                ->groupBy('source_name')->orderByDesc('total')->toBase()->limit(20)->get(),
            'metaPages' => $request->user()->can('manageCrm', $org) ? DB::table('crm_meta_pages')->where('organization_id', $org->id)->get(['id', 'page_id', 'active', 'subscribed_at']) : [],
            'canConfigure' => $request->user()->can('manageCrm', $org),
        ]);
    }

    public function followUpSettings(Request $request): Response
    {
        $org = $this->org($request);
        $this->authorize('viewCrm', $org);

        return Inertia::render('crm/FollowUpSettings', [
            'reminderDays' => $org->crm_follow_up_reminder_days,
            'escalationEnabled' => $org->crm_follow_up_escalation_enabled,
            'stageRules' => DB::table('crm_pipeline_stages as stage')->join('crm_pipelines as pipeline', 'pipeline.id', '=', 'stage.pipeline_id')
                ->where('pipeline.organization_id', $org->id)->get(['stage.id', 'pipeline.name as pipeline_name', 'stage.name', 'stage.follow_up_due_days', 'stage.notify_assignee_on_entry']),
            'canConfigure' => $request->user()->can('manageCrmPipelines', $org),
        ]);
    }

    private function org(Request $request): Organization
    {
        $org = $request->user()->currentOrganization;
        abort_unless($org !== null && $request->user()->belongsToOrganization($org), 404);

        return $org;
    }
}
