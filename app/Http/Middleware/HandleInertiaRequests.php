<?php

namespace App\Http\Middleware;

use App\Domain\Crm\Services\LeadVisibility;
use App\Domain\Platform\Queries\PendingApprovals;
use App\Models\CrmLead;
use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'locale' => app()->getLocale(),
            'auth' => [
                'user' => $request->user(),
            ],
            'organization' => fn () => $request->user()?->currentOrganization?->only('id', 'name', 'slug'),
            'abilities' => fn () => $this->abilities($request),
            'counts' => fn () => $this->counts($request),
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }

    /** @return array<string, bool> */
    private function abilities(Request $request): array
    {
        $user = $request->user();
        $organization = $user?->currentOrganization;
        $can = fn (string $ability): bool => $organization instanceof Organization && $user->can($ability, $organization);

        return [
            'crm' => $can('viewCrm'),
            'listings' => $can('viewInventory'),
            'leasing' => $can('viewTransactions'),
            'deals' => $can('viewTransactions'),
            'commission' => $can('viewFinance'),
            'accounting' => $can('viewFinance'),
            'pdc' => $can('viewTransactions'),
            'procurement' => $can('viewOperations'),
            'operations' => $can('viewOperations'),
            'fleet' => $can('manageOperations'),
            'projects' => $can('manageOperations'),
            'reports' => $can('viewOperations') || $can('viewFinance') || $can('viewCrm'),
            'organization_admin' => $can('update'),
        ];
    }

    /** @return array{notifications_unread: int, crm_open_leads: int, approvals_pending: int} */
    private function counts(Request $request): array
    {
        $user = $request->user();
        $organization = $user?->currentOrganization;
        if (! $organization) {
            return ['notifications_unread' => 0, 'crm_open_leads' => 0, 'approvals_pending' => 0];
        }

        return [
            'notifications_unread' => DB::table('organization_notifications')->where('organization_id', $organization->id)->where('user_id', $user->id)->whereNull('read_at')->count(),
            'crm_open_leads' => $user->can('viewCrm', $organization)
                ? app(LeadVisibility::class)->scope(CrmLead::query()->where('organization_id', $organization->id)->whereNotIn('status', ['converted', 'lost']), $organization, $user)->count()
                : 0,
            'approvals_pending' => count(app(PendingApprovals::class)->for($organization, $user)),
        ];
    }
}
