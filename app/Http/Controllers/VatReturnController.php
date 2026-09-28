<?php

namespace App\Http\Controllers;

use App\Domain\Accounting\Actions\ManageVatReturn;
use App\Domain\Accounting\Actions\PostVatSettlement;
use App\Domain\Accounting\Models\BankAccount;
use App\Domain\Accounting\Models\VatReturn;
use App\Domain\Accounting\Models\VatSettlement;
use App\Domain\Accounting\Queries\VatReturnPreparation;
use App\Domain\Identity\Enums\OrganizationRole;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class VatReturnController extends Controller
{
    public function index(Request $request, VatReturnPreparation $query): Response
    {
        [$organization, $from, $to] = $this->context($request);

        $return = VatReturn::where('organization_id', $organization->id)->where('starts_on', $from)->where('ends_on', $to)->with(['preparer:id,name', 'filer:id,name', 'adjustments.recorder:id,name', 'settlement'])->first();

        return Inertia::render('finance/VatReturn', ['report' => $query->for($organization, $from, $to), 'vatReturn' => $return, 'bankAccounts' => BankAccount::where('organization_id', $organization->id)->where('currency', 'AED')->whereNotNull('ledger_account_id')->get(['id', 'name']), 'frequency' => $organization->vat_return_frequency, 'trn' => $organization->tax_registration_number, 'canManage' => $request->user()->can('manageFinance', $organization), 'canFile' => $request->user()->hasOrganizationRole($organization, OrganizationRole::Owner)]);
    }

    public function prepare(Request $request, VatReturnPreparation $query, ManageVatReturn $manage): RedirectResponse
    {
        [$organization, $from, $to] = $this->context($request);
        $this->authorize('manageFinance', $organization);
        $manage->prepare($organization, $request->user(), $query->for($organization, $from, $to));

        return back();
    }

    public function file(Request $request, VatReturn $vatReturn, ManageVatReturn $manage): RedirectResponse
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization && $request->user()->hasOrganizationRole($organization, OrganizationRole::Owner), 403);
        abort_unless($vatReturn->organization_id === $organization->id, 404);
        $input = $request->validate(['filed_on' => ['required', 'date_format:Y-m-d'], 'fta_reference' => ['required', 'string', 'max:100']]);
        $manage->file($organization, $request->user(), $vatReturn, $input);

        return back();
    }

    public function adjust(Request $request, VatReturn $vatReturn, ManageVatReturn $manage): RedirectResponse
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization && $vatReturn->organization_id === $organization->id, 404);
        $this->authorize('manageFinance', $organization);
        $input = $request->validate(['discovered_on' => ['required', 'date_format:Y-m-d'], 'output_vat_delta' => ['required', 'numeric', 'decimal:0,2'], 'input_vat_delta' => ['required', 'numeric', 'decimal:0,2'], 'correction_method' => ['required', 'in:current_return,voluntary_disclosure'], 'reason' => ['required', 'string', 'max:5000']]);
        $manage->adjust($organization, $request->user(), $vatReturn, $input);

        return back();
    }

    public function settle(Request $request, VatReturn $vatReturn, PostVatSettlement $post): RedirectResponse
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization && $vatReturn->organization_id === $organization->id, 404);
        $this->authorize('manageFinance', $organization);
        $input = $request->validate(['bank_account_id' => ['required', 'integer'], 'posted_on' => ['required', 'date_format:Y-m-d']]);
        $post->handle($organization, $request->user(), $vatReturn, (int) $input['bank_account_id'], $input['posted_on']);

        return back();
    }

    public function receiveRefund(Request $request, VatSettlement $settlement, PostVatSettlement $post): RedirectResponse
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization && $settlement->organization_id === $organization->id, 404);
        $this->authorize('manageFinance', $organization);
        $date = $request->validate(['posted_on' => ['required', 'date_format:Y-m-d']])['posted_on'];
        $post->receiveRefund($organization, $request->user(), $settlement, $date);

        return back();
    }

    public function reverseSettlement(Request $request, VatSettlement $settlement, PostVatSettlement $post): RedirectResponse
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization && $settlement->organization_id === $organization->id, 404);
        $this->authorize('manageFinance', $organization);
        $date = $request->validate(['posted_on' => ['required', 'date_format:Y-m-d']])['posted_on'];
        $post->reverse($organization, $request->user(), $settlement, $date);

        return back();
    }

    public function reverseRefund(Request $request, VatSettlement $settlement, PostVatSettlement $post): RedirectResponse
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization && $settlement->organization_id === $organization->id, 404);
        $this->authorize('manageFinance', $organization);
        $date = $request->validate(['posted_on' => ['required', 'date_format:Y-m-d']])['posted_on'];
        $post->reverseRefundReceipt($organization, $request->user(), $settlement, $date);

        return back();
    }

    public function export(Request $request, VatReturnPreparation $query): StreamedResponse
    {
        [$organization, $from, $to] = $this->context($request);
        $report = $query->for($organization, $from, $to);

        return response()->streamDownload(function () use ($report): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                throw new \RuntimeException('Unable to open CSV output stream.');
            }
            fputcsv($out, ['VAT201 preparation', $report['from'], $report['to']]);
            foreach ($report['totals'] as $label => $amount) {
                fputcsv($out, [str_replace('_', ' ', $label), $amount]);
            }
            fputcsv($out, []);
            fputcsv($out, ['Sales', 'Date', 'Reference', 'Treatment', 'Net', 'VAT', 'Gross']);
            foreach ($report['sales'] as $row) {
                fputcsv($out, ['', ...array_values($row)]);
            }
            fputcsv($out, []);
            fputcsv($out, ['Purchases', 'Date', 'Reference', 'Treatment', 'Gross', 'VAT', 'Recoverable VAT']);
            foreach ($report['purchases'] as $row) {
                fputcsv($out, ['', ...array_values($row)]);
            }
            fclose($out);
        }, "vat201-preparation-{$from}-{$to}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @return array{Organization, string, string}
     */
    private function context(Request $request): array
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization && $organization->vat_enabled, 404);
        $this->authorize('viewFinance', $organization);
        $input = $request->validate(['period' => ['nullable', 'date_format:Y-m']]);
        $month = CarbonImmutable::createFromFormat('!Y-m', $input['period'] ?? today()->format('Y-m'));
        $from = $organization->vat_return_frequency === 'monthly' ? $month->startOfMonth() : $month->firstOfQuarter();
        $to = $organization->vat_return_frequency === 'monthly' ? $month->endOfMonth() : $month->lastOfQuarter();

        return [$organization, $from->toDateString(), $to->toDateString()];
    }
}
