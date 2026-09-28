<?php

namespace App\Http\Controllers;

use App\Domain\Leasing\Queries\OwnerStatement;
use App\Models\Organization;
use App\Models\Owner;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OwnerStatementController extends Controller
{
    public function index(Request $request, OwnerStatement $statement): Response
    {
        [$organization, $owner, $from, $to] = $this->context($request);

        return Inertia::render('finance/OwnerStatements', [
            'owners' => Owner::where('organization_id', $organization->id)->orderBy('name')->get(['id', 'name', 'reference']),
            'selectedOwnerId' => $owner?->id,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'statement' => $owner ? $statement->for($organization, $owner, $from, $to) : null,
        ]);
    }

    public function export(Request $request, OwnerStatement $statement): StreamedResponse
    {
        [$organization, $owner, $from, $to] = $this->context($request);
        abort_unless($owner !== null, 422, 'Select an owner before exporting.');
        $report = $statement->for($organization, $owner, $from, $to);

        return response()->streamDownload(function () use ($report): void {
            $stream = fopen('php://output', 'w');
            if ($stream === false) {
                throw new \RuntimeException('Unable to open CSV output stream.');
            }
            fputcsv($stream, ['Date', 'Type', 'Reference', 'Property', 'Description', 'Property amount AED', 'Ownership %', 'Owner amount AED']);
            foreach ($report['rows'] as $row) {
                fputcsv($stream, [$row['activity_date'], $row['type'], $row['reference'], $row['property_name'], $row['description'], $row['property_amount'], $row['ownership_share'], $row['owner_amount']]);
            }
            fputcsv($stream, []);
            fputcsv($stream, ['Income', $report['totals']['income']]);
            fputcsv($stream, ['Expenses', $report['totals']['expenses']]);
            fputcsv($stream, ['Net', $report['totals']['net']]);
            fclose($stream);
        }, "owner-statement-{$owner->id}-{$from->toDateString()}-{$to->toDateString()}.csv", ['Content-Type' => 'text/csv']);
    }

    /** @return array{0: Organization, 1: Owner|null, 2: Carbon, 3: Carbon} */
    private function context(Request $request): array
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization !== null, 404);
        $this->authorize('viewFinance', $organization);
        $input = $request->validate(['owner_id' => ['nullable', 'integer'], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);
        $from = Carbon::parse($input['from'] ?? now()->startOfYear()->toDateString())->startOfDay();
        $to = Carbon::parse($input['to'] ?? today()->toDateString())->endOfDay();
        $owner = isset($input['owner_id'])
            ? Owner::where('organization_id', $organization->id)->findOrFail((int) $input['owner_id'])
            : Owner::where('organization_id', $organization->id)->orderBy('name')->first();

        return [$organization, $owner, $from, $to];
    }
}
