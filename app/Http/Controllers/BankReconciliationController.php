<?php

namespace App\Http\Controllers;

use App\Domain\Accounting\Actions\ReconcileBankStatement;
use App\Domain\Accounting\Models\BankAccount;
use App\Domain\Accounting\Models\BankStatementLine;
use App\Domain\Accounting\Models\JournalLine;
use App\Domain\Accounting\Models\LedgerAccount;
use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class BankReconciliationController extends Controller
{
    public function index(Request $request): Response
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization !== null, 404);
        $this->authorize('viewFinance', $organization);
        $filters = $request->validate([
            'bank_account_id' => ['nullable', 'integer', Rule::exists('bank_accounts', 'id')->where('organization_id', $organization->id)],
            'status' => ['nullable', Rule::in(['all', 'matched', 'unmatched'])],
        ]);

        $lines = BankStatementLine::where('organization_id', $organization->id)
            ->when($filters['bank_account_id'] ?? null, fn ($query, $id) => $query->where('bank_account_id', $id))
            ->when(($filters['status'] ?? 'all') === 'matched', fn ($query) => $query->whereNotNull('matched_journal_line_id'))
            ->when(($filters['status'] ?? 'all') === 'unmatched', fn ($query) => $query->whereNull('matched_journal_line_id'))
            ->with(['bankAccount:id,name', 'settlements' => fn ($query) => $query->whereNull('reversal_journal_entry_id')->with('journalEntry:id,reference')])->latest()->paginate(30)->withQueryString();
        $amounts = $lines->getCollection()->pluck('amount')->map(fn (string $amount) => number_format(abs((float) $amount), 2, '.', ''))->unique()->values()->all();
        $candidates = $amounts === [] ? collect() : JournalLine::with(['entry:id,organization_id,reference,event,posted_on,currency', 'account:id,organization_id,code,name'])
            ->whereHas('entry', fn ($query) => $query->where('organization_id', $organization->id)->whereIn('event', ['payment.received', 'vendor_bill.paid', 'deposit.refund_approved', 'customer.refund_approved'])->where('currency', 'AED'))
            ->whereHas('account', fn ($query) => $query->where('organization_id', $organization->id)->whereIn('code', ['1150', '1190']))
            ->where(fn ($query) => $query->whereIn('debit', $amounts)->orWhereIn('credit', $amounts))
            ->whereNotIn('id', BankStatementLine::whereNotNull('matched_journal_line_id')->select('matched_journal_line_id'))
            ->latest()->limit(500)->get();

        return Inertia::render('finance/BankReconciliation', [
            'accounts' => BankAccount::where('organization_id', $organization->id)->orderBy('name')->get(['id', 'name', 'ledger_account_id']),
            'assetAccounts' => LedgerAccount::where('organization_id', $organization->id)->where('type', 'asset')->where('is_active', true)->whereNotIn('code', ['1150', '1190'])->orderBy('code')->get(['id', 'code', 'name']),
            'lines' => $lines,
            'candidates' => $candidates,
            'filters' => ['bank_account_id' => (string) ($filters['bank_account_id'] ?? ''), 'status' => $filters['status'] ?? 'all'],
            'canManage' => $request->user()->can('manageFinance', $organization),
        ]);
    }

    public function storeAccount(Request $request, RecordOrganizationAuditLog $audit): RedirectResponse
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization !== null, 404);
        $this->authorize('manageFinance', $organization);
        $input = $request->validate(['name' => ['required', 'string', 'max:255', Rule::unique('bank_accounts', 'name')->where('organization_id', $organization->id)]]);
        $account = BankAccount::create(['organization_id' => $organization->id, 'name' => $input['name'], 'currency' => 'AED']);
        $audit->handle($organization, $request->user(), 'accounting.bank_account.created', $account);

        return back();
    }

    public function import(Request $request, ReconcileBankStatement $reconciliation): RedirectResponse
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization !== null, 404);
        $this->authorize('manageFinance', $organization);
        $input = $request->validate(['bank_account_id' => ['required', 'integer'], 'file' => ['required', 'file', 'max:2048']]);
        $account = BankAccount::where('organization_id', $organization->id)->findOrFail((int) $input['bank_account_id']);
        $reconciliation->import($organization, $request->user(), $account, $input['file']);

        return back();
    }

    public function linkLedgerAccount(Request $request, BankAccount $bankAccount, ReconcileBankStatement $reconciliation): RedirectResponse
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization && $bankAccount->organization_id === $organization->id, 404);
        $this->authorize('manageFinance', $organization);
        $id = $request->validate(['ledger_account_id' => ['required', 'integer']])['ledger_account_id'];
        $reconciliation->linkLedgerAccount($organization, $request->user(), $bankAccount, LedgerAccount::findOrFail((int) $id));

        return back();
    }

    public function settle(Request $request, BankStatementLine $statementLine, ReconcileBankStatement $reconciliation): RedirectResponse
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization && $statementLine->organization_id === $organization->id, 404);
        $this->authorize('manageFinance', $organization);
        $reconciliation->settle($organization, $request->user(), $statementLine);

        return back();
    }

    public function reverseSettlement(Request $request, BankStatementLine $statementLine, ReconcileBankStatement $reconciliation): RedirectResponse
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization && $statementLine->organization_id === $organization->id, 404);
        $this->authorize('manageFinance', $organization);
        $date = $request->validate(['posted_on' => ['required', 'date']])['posted_on'];
        $reconciliation->reverseSettlement($organization, $request->user(), $statementLine, $date);

        return back();
    }

    public function match(Request $request, BankStatementLine $statementLine, ReconcileBankStatement $reconciliation): RedirectResponse
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization && $statementLine->organization_id === $organization->id, 404);
        $this->authorize('manageFinance', $organization);
        $id = $request->validate(['journal_line_id' => ['required', 'integer']])['journal_line_id'];
        $reconciliation->match($organization, $request->user(), $statementLine, JournalLine::findOrFail((int) $id));

        return back();
    }

    public function unmatch(Request $request, BankStatementLine $statementLine, ReconcileBankStatement $reconciliation): RedirectResponse
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization && $statementLine->organization_id === $organization->id, 404);
        $this->authorize('manageFinance', $organization);
        $reconciliation->unmatch($organization, $request->user(), $statementLine);

        return back();
    }
}
