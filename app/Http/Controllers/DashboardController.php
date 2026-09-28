<?php

namespace App\Http\Controllers;

use App\Domain\Crm\Services\LeadVisibility;
use App\Domain\Finance\Services\InvoiceBalance;
use App\Domain\Identity\Actions\CreateOrganizationForUser;
use App\Domain\Operations\Services\JobCardAccess;
use App\Domain\Platform\Queries\HomeDashboard;
use App\Models\CrmLead;
use App\Models\Invoice;
use App\Models\MaintenanceRequest;
use App\Models\Unit;
use App\Support\CurrentOperationalAlerts;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request, CurrentOperationalAlerts $currentAlerts, LeadVisibility $visibility, HomeDashboard $homeDashboard): Response
    {
        $organization = $request->user()->currentOrganization
            ?? app(CreateOrganizationForUser::class)->handle($request->user());

        $invoices = $request->user()->can('viewFinance', $organization)
            ? Invoice::where('organization_id', $organization->id)->withSum('payments', 'amount')->get()
            : null;
        $alerts = $currentAlerts->forOrganization($organization->id, $request->user());

        return Inertia::render('Dashboard', [
            'metrics' => [
                'openMaintenance' => app(JobCardAccess::class)->scope(MaintenanceRequest::query(), $organization, $request->user())->whereIn('status', ['open', 'in_progress'])->count(),
                'overdueMaintenance' => app(JobCardAccess::class)->scope(MaintenanceRequest::query(), $organization, $request->user())->whereIn('status', ['open', 'in_progress'])->where('due_at', '<', now())->count(),
                'availableUnits' => Unit::where('organization_id', $organization->id)->where('status', 'available')->count(),
                'reservedUnits' => Unit::where('organization_id', $organization->id)->where('status', 'reserved')->count(),
                'activeLeads' => $visibility->scope(CrmLead::where('organization_id', $organization->id)->whereNotIn('status', ['converted', 'lost']), $organization, $request->user())->count(),
                'outstandingAed' => $invoices?->sum(fn (Invoice $invoice) => app(InvoiceBalance::class)->outstandingCents($invoice) / 100),
            ],
            'alerts' => collect($alerts)->filter(fn (array $alert) => $alert['count'] > 0)->values(),
            ...$homeDashboard->for($organization, $request->user(), $request->validate([
                'period' => ['sometimes', 'in:month,quarter,year'],
                'purpose' => ['sometimes', 'in:all,sale,rent'],
                'company' => ['sometimes', 'integer', 'min:1'],
                'branch' => ['sometimes', 'integer', 'min:1'],
            ])),
        ]);
    }
}
