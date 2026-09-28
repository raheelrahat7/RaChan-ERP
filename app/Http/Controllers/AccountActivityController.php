<?php

namespace App\Http\Controllers;

use App\Domain\Accounting\Models\LedgerAccount;
use App\Domain\Accounting\Queries\AccountActivity;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AccountActivityController extends Controller
{
    public function __invoke(Request $request, AccountActivity $activity): Response
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization !== null, 404);
        $this->authorize('viewFinance', $organization);
        $filters = $request->validate([
            'account_id' => ['nullable', 'integer', Rule::exists('ledger_accounts', 'id')->where('organization_id', $organization->id)],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $account = isset($filters['account_id']) ? LedgerAccount::where('organization_id', $organization->id)->findOrFail((int) $filters['account_id']) : null;

        return Inertia::render('finance/AccountActivity', [
            'accounts' => LedgerAccount::where('organization_id', $organization->id)->orderBy('code')->get(['id', 'code', 'name']),
            'selectedAccount' => $account?->only('id', 'code', 'name', 'type'),
            'filters' => ['account_id' => (string) ($filters['account_id'] ?? ''), 'from' => $filters['from'] ?? '', 'to' => $filters['to'] ?? ''],
            'activity' => $account ? $activity->forAccount($organization, $account, $filters['from'] ?? null, $filters['to'] ?? null) : null,
        ]);
    }
}
