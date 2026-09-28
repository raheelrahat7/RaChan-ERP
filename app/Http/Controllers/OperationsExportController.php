<?php

namespace App\Http\Controllers;

use App\Domain\Crm\Queries\LeadSummaryExport;
use App\Domain\Finance\Queries\InvoiceSummaryExport;
use App\Domain\Operations\Queries\MaintenanceExport;
use App\Domain\RealEstate\Queries\InventoryExport;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OperationsExportController extends Controller
{
    public function __invoke(Request $request, string $report, MaintenanceExport $maintenance, InventoryExport $inventory, LeadSummaryExport $leads, InvoiceSummaryExport $invoices): StreamedResponse
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization !== null, 404);
        $permission = match ($report) {
            'maintenance' => 'viewOperations',
            'inventory' => 'viewInventory',
            'leads' => 'viewCrm',
            'receivables' => 'viewFinance',
            default => abort(404),
        };
        $this->authorize($permission, $organization);
        $rows = match ($report) {
            'maintenance' => $maintenance->rows($organization, $request->user()),
            'inventory' => $inventory->rows($organization),
            'leads' => $leads->rows($organization, $request->user()),
            'receivables' => $invoices->rows($organization),
        };

        return response()->streamDownload(function () use ($rows): void {
            $output = fopen('php://output', 'w');
            if ($output === false) {
                throw new \RuntimeException('Unable to open CSV output stream.');
            }
            try {
                foreach ($rows as $row) {
                    fputcsv($output, array_map(function (mixed $value): mixed {
                        return is_string($value) && preg_match('/^[\s]*[=+\-@]/u', $value) ? "'".$value : $value;
                    }, $row));
                }
            } finally {
                fclose($output);
            }
        }, "{$report}-report.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
