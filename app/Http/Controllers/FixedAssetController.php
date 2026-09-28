<?php

namespace App\Http\Controllers;

use App\Domain\Accounting\Actions\ApproveFixedAssetEstimateChange;
use App\Domain\Accounting\Actions\PostDepreciationRun;
use App\Domain\Accounting\Actions\PostFixedAssetDisposal;
use App\Domain\Accounting\Actions\PostFixedAssetImpairment;
use App\Domain\Accounting\Actions\RecordFixedAssetReview;
use App\Domain\Accounting\Actions\RegisterFixedAsset;
use App\Domain\Accounting\Actions\TransferFixedAsset;
use App\Domain\Accounting\Models\BankAccount;
use App\Domain\Accounting\Models\FixedAsset;
use App\Domain\Accounting\Models\FixedAssetDepreciation;
use App\Domain\Accounting\Models\FixedAssetDisposal;
use App\Domain\Accounting\Models\FixedAssetImpairment;
use App\Domain\Accounting\Models\FixedAssetReview;
use App\Domain\Accounting\Models\FixedAssetTransfer;
use App\Domain\Accounting\Models\LedgerAccount;
use App\Domain\Accounting\Queries\DepreciationPreview;
use App\Domain\Accounting\Queries\FixedAssetReviewReadiness;
use App\Models\JournalEntry;
use App\Models\Property;
use App\Models\VendorBill;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FixedAssetController extends Controller
{
    public function index(Request $request, DepreciationPreview $preview, FixedAssetReviewReadiness $readiness): Response
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization !== null, 404);
        $this->authorize('viewFinance', $organization);
        $filters = $request->validate([
            'month' => ['nullable', 'date_format:Y-m'],
            'review_year' => ['nullable', 'integer', 'min:2000', 'max:2100'],
        ]);
        $month = $filters['month'] ?? today()->format('Y-m');
        $reviewYear = (int) ($filters['review_year'] ?? today()->year);

        return Inertia::render('finance/FixedAssets', [
            'assets' => FixedAsset::where('organization_id', $organization->id)->with(['property:id,name', 'vendorBill:id,reference', 'creator:id,name'])->orderBy('reference')->get(),
            'properties' => Property::where('organization_id', $organization->id)->orderBy('name')->get(['id', 'name']),
            'vendorBills' => VendorBill::where('organization_id', $organization->id)->whereNot('status', 'draft')->where('accounting_treatment', 'capital_asset')->where('currency', 'AED')->orderBy('reference')->get(['id', 'reference', 'total']),
            'openingSources' => JournalEntry::where('organization_id', $organization->id)->where('event', 'opening.balance')->where('currency', 'AED')->whereNotNull('source_reference')->whereHas('lines.account', fn ($query) => $query->where('code', '1500'))->orderBy('source_reference')->pluck('source_reference'),
            'canManage' => $request->user()->can('manageFinance', $organization),
            'depreciationPreview' => $preview->for($organization, $month),
            'depreciations' => FixedAssetDepreciation::where('organization_id', $organization->id)->with(['asset:id,reference,name', 'approver:id,name'])->latest()->limit(100)->get(),
            'reviews' => FixedAssetReview::where('organization_id', $organization->id)->with(['asset:id,reference,name', 'reviewer:id,name', 'estimateChange', 'impairments'])->orderByDesc('review_year')->latest()->limit(100)->get(),
            'reviewReadiness' => $readiness->for($organization, $reviewYear),
            'proceedsAccounts' => LedgerAccount::where('organization_id', $organization->id)->where('is_active', true)->where('type', 'asset')->where(fn ($query) => $query->where('code', '1100')->orWhereIn('id', BankAccount::where('organization_id', $organization->id)->where('currency', 'AED')->whereNotNull('ledger_account_id')->pluck('ledger_account_id')))->orderBy('code')->get(['id', 'code', 'name']),
            'disposals' => FixedAssetDisposal::where('organization_id', $organization->id)->with(['asset:id,reference,name', 'proceedsAccount:id,code,name', 'approver:id,name'])->latest()->limit(100)->get(),
            'impairments' => FixedAssetImpairment::where('organization_id', $organization->id)->with(['asset:id,reference,name', 'approver:id,name'])->latest()->limit(100)->get(),
            'transfers' => FixedAssetTransfer::where('organization_id', $organization->id)->with(['asset:id,reference,name', 'fromProperty:id,name', 'toProperty:id,name', 'approver:id,name'])->latest('transferred_on')->latest()->limit(100)->get(),
        ]);
    }

    public function exportReviewReadiness(Request $request, FixedAssetReviewReadiness $readiness): StreamedResponse
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization !== null, 404);
        $this->authorize('viewFinance', $organization);
        $year = (int) $request->validate(['review_year' => ['required', 'integer', 'min:2000', 'max:2100']])['review_year'];
        $report = $readiness->for($organization, $year);

        return response()->streamDownload(function () use ($report): void {
            $output = fopen('php://output', 'w');
            if ($output === false) {
                throw new \RuntimeException('Unable to open CSV output stream.');
            }
            fputcsv($output, ['Fixed asset annual review readiness', 'Year', $report['year'], 'Eligible assets', $report['eligible_count']]);
            fputcsv($output, []);
            fputcsv($output, ['Status', 'Asset reference', 'Asset name', 'Outcome', 'Impairment assessment', 'Notes']);
            foreach ($report['missing'] as $asset) {
                fputcsv($output, ['Missing review', $this->safeCell($asset->reference), $this->safeCell($asset->name), '', '', '']);
            }
            foreach ($report['attention'] as $review) {
                fputcsv($output, ['Follow-up required', $this->safeCell($review->asset->reference), $this->safeCell($review->asset->name), str_replace('_', ' ', $review->outcome), $review->impairment_assessment_required ? 'Required' : 'Not indicated', $this->safeCell($review->notes)]);
            }
            fclose($output);
        }, "fixed-asset-review-readiness-{$year}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function store(Request $request, RegisterFixedAsset $register): RedirectResponse
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization !== null, 404);
        $this->authorize('manageFinance', $organization);
        $input = $request->validate([
            'source_type' => ['required', 'in:vendor_bill,opening_balance'],
            'vendor_bill_id' => ['nullable', 'required_if:source_type,vendor_bill', 'prohibited_if:source_type,opening_balance', 'integer'],
            'opening_source_reference' => ['nullable', 'required_if:source_type,opening_balance', 'prohibited_if:source_type,vendor_bill', 'string', 'max:100'],
            'reference' => ['required', 'string', 'max:100', Rule::unique('fixed_assets')->where('organization_id', $organization->id)],
            'name' => ['required', 'string', 'max:255'], 'asset_class' => ['required', 'string', 'max:100'],
            'cost' => ['required', 'numeric', 'min:0.01', 'decimal:0,2'], 'residual_value' => ['required', 'numeric', 'min:0', 'lte:cost', 'decimal:0,2'],
            'useful_life_months' => ['required', 'integer', 'min:1', 'max:1200'], 'available_for_use_on' => ['required', 'date_format:Y-m-d'],
        ]);
        $register->handle($organization, $request->user(), $input);

        return back();
    }

    public function dispose(Request $request, FixedAsset $asset, RegisterFixedAsset $register): RedirectResponse
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization && $asset->organization_id === $organization->id, 404);
        $this->authorize('manageFinance', $organization);
        $disposedOn = $request->validate(['disposed_on' => ['required', 'date_format:Y-m-d']])['disposed_on'];
        $register->dispose($organization, $request->user(), $asset, $disposedOn);

        return back();
    }

    public function transfer(Request $request, FixedAsset $asset, TransferFixedAsset $transfer): RedirectResponse
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization && $asset->organization_id === $organization->id, 404);
        $this->authorize('manageFinance', $organization);
        $input = $request->validate([
            'transferred_on' => ['required', 'date_format:Y-m-d'],
            'property_id' => ['nullable', 'integer'],
            'location' => ['nullable', 'string', 'max:255'],
            'custodian' => ['nullable', 'string', 'max:255'],
            'asset_class' => ['required', 'string', 'max:100'],
            'reason' => ['required', 'string', 'max:5000'],
        ]);
        if (isset($input['property_id'])) {
            Property::where('organization_id', $organization->id)->findOrFail((int) $input['property_id']);
        }
        $transfer->handle($organization, $request->user(), $asset, $input);

        return back();
    }

    public function postDepreciation(Request $request, PostDepreciationRun $run): RedirectResponse
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization !== null, 404);
        $this->authorize('manageFinance', $organization);
        $month = $request->validate(['month' => ['required', 'date_format:Y-m']])['month'];
        $run->handle($organization, $request->user(), $month);

        return back();
    }

    public function reverseDepreciation(Request $request, FixedAssetDepreciation $depreciation, PostDepreciationRun $run): RedirectResponse
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization && $depreciation->organization_id === $organization->id, 404);
        $this->authorize('manageFinance', $organization);
        $date = $request->validate(['posted_on' => ['required', 'date_format:Y-m-d']])['posted_on'];
        $run->reverse($organization, $request->user(), $depreciation, $date);

        return back();
    }

    public function storeReview(Request $request, FixedAsset $asset, RecordFixedAssetReview $record): RedirectResponse
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization && $asset->organization_id === $organization->id, 404);
        $this->authorize('manageFinance', $organization);
        $input = $request->validate([
            'review_year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'reviewed_on' => ['required', 'date_format:Y-m-d'],
            'outcome' => ['required', 'in:unchanged,change_required'],
            'impairment_assessment_required' => ['required', 'boolean'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);
        abort_if((int) substr($input['reviewed_on'], 0, 4) !== (int) $input['review_year'], 422, 'The review date must fall within the review year.');
        abort_if($input['outcome'] === 'change_required' && blank($input['notes']), 422, 'Document the required estimate change.');
        $record->handle($organization, $request->user(), $asset, $input);

        return back();
    }

    public function approveEstimateChange(Request $request, FixedAssetReview $review, ApproveFixedAssetEstimateChange $approve): RedirectResponse
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization && $review->organization_id === $organization->id, 404);
        $this->authorize('manageFinance', $organization);
        $input = $request->validate(['residual_value' => ['required', 'numeric', 'min:0', 'decimal:0,2'], 'remaining_life_months' => ['required', 'integer', 'min:1', 'max:1200']]);
        $approve->handle($organization, $request->user(), $review, $input);

        return back();
    }

    public function postDisposal(Request $request, FixedAsset $asset, PostFixedAssetDisposal $post): RedirectResponse
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization && $asset->organization_id === $organization->id, 404);
        $this->authorize('manageFinance', $organization);
        $input = $request->validate(['proceeds_account_id' => ['required', 'integer'], 'proceeds' => ['required', 'numeric', 'min:0', 'decimal:0,2']]);
        $post->handle($organization, $request->user(), $asset, (int) $input['proceeds_account_id'], $input['proceeds']);

        return back();
    }

    public function reverseDisposal(Request $request, FixedAssetDisposal $disposal, PostFixedAssetDisposal $post): RedirectResponse
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization && $disposal->organization_id === $organization->id, 404);
        $this->authorize('manageFinance', $organization);
        $date = $request->validate(['posted_on' => ['required', 'date_format:Y-m-d']])['posted_on'];
        $post->reverse($organization, $request->user(), $disposal, $date);

        return back();
    }

    public function postImpairment(Request $request, FixedAssetReview $review, PostFixedAssetImpairment $post): RedirectResponse
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization && $review->organization_id === $organization->id, 404);
        $this->authorize('manageFinance', $organization);
        $input = $request->validate(['posted_on' => ['required', 'date_format:Y-m-d'], 'amount' => ['required', 'numeric', 'min:0.01', 'decimal:0,2']]);
        $post->handle($organization, $request->user(), $review, $input['posted_on'], $input['amount']);

        return back();
    }

    public function reverseImpairment(Request $request, FixedAssetImpairment $impairment, PostFixedAssetImpairment $post): RedirectResponse
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization && $impairment->organization_id === $organization->id, 404);
        $this->authorize('manageFinance', $organization);
        $date = $request->validate(['posted_on' => ['required', 'date_format:Y-m-d']])['posted_on'];
        $post->reverse($organization, $request->user(), $impairment, $date);

        return back();
    }

    private function safeCell(?string $value): string
    {
        $value ??= '';

        return preg_match('/^[\s]*[=+\-@]/u', $value) ? "'".$value : $value;
    }
}
