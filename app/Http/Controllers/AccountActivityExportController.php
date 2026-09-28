<?php

namespace App\Http\Controllers;

use App\Domain\Accounting\Models\LedgerAccount;
use App\Domain\Accounting\Queries\AccountActivity;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AccountActivityExportController extends Controller
{
    public function __invoke(Request $request, AccountActivity $activity): StreamedResponse
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization !== null, 404);
        $this->authorize('viewFinance', $organization);
        $filters = $request->validate([
            'account_id' => ['required', 'integer', Rule::exists('ledger_accounts', 'id')->where('organization_id', $organization->id)],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $account = LedgerAccount::where('organization_id', $organization->id)->findOrFail((int) $filters['account_id']);
        $summary = $activity->forAccount($organization, $account, $filters['from'] ?? null, $filters['to'] ?? null);
        $lines = $activity->exportLines($organization, $account, $filters['from'] ?? null, $filters['to'] ?? null);

        return response()->streamDownload(function () use ($account, $filters, $summary, $lines): void {
            $output = fopen('php://output', 'w');
            if ($output === false) {
                throw new \RuntimeException('Unable to open CSV output stream.');
            }
            fputcsv($output, ['Account', $account->code.' · '.$this->safeCell($account->name)]);
            fputcsv($output, ['From', $filters['from'] ?? '', 'To', $filters['to'] ?? '']);
            fputcsv($output, ['Opening net AED', $summary['opening_net'], 'Period debits AED', $summary['period_debit'], 'Period credits AED', $summary['period_credit'], 'Closing net AED', $summary['closing_net']]);
            fputcsv($output, []);
            fputcsv($output, ['Date', 'Journal', 'Source reference', 'Event', 'Description', 'Debit AED', 'Credit AED', 'Reversal']);
            foreach ($lines as $line) {
                fputcsv($output, [$line->entry->posted_on->toDateString(), $line->entry->reference, $this->safeCell($line->entry->source_reference), $line->entry->event, $this->safeCell($line->description), $line->debit, $line->credit, $line->entry->reversal_of_id ? 'Yes' : 'No']);
            }
            fclose($output);
        }, 'account-'.$account->code.'-activity.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function safeCell(?string $value): string
    {
        $value ??= '';

        return preg_match('/^[\s]*[=+\-@]/u', $value) ? "'".$value : $value;
    }
}
