<?php

namespace App\Domain\Crm\Queries;

use App\Domain\Crm\Services\DealAccess;
use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Models\Organization;
use App\Models\User;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportDeals
{
    public function __construct(private DealAccess $access, private DealOverview $overview, private RecordOrganizationAuditLog $audit) {}

    /** @param array<string, mixed> $filters */
    public function download(Organization $org, User $actor, array $filters): StreamedResponse
    {
        $read = $this->overview->query($org, $actor, $filters);
        $query = $this->access->query($org, $actor, 'export')->whereIn('id', $read->select('id'))->with(['pipeline', 'stage', 'assignee:id,name']);
        abort_unless(collect($this->overview->pipelines($org, $actor))->contains(fn ($p) => $p['permissions']['export'] !== []), 403);
        $this->audit->handle($org, $actor, 'crm.deals.exported', null, ['record_count' => (clone $query)->count(), 'filters' => $filters]);

        return response()->streamDownload(function () use ($org, $actor, $query): void {
            $stream = fopen('php://output', 'w');
            if ($stream === false) {
                return;
            }
            fputcsv($stream, ['ID', 'Title', 'Category', 'Pipeline', 'Stage', 'Responsible person', 'Email', 'Phone', 'Amount', 'Currency'], ',', '"', '');
            foreach ($query->orderBy('id')->lazyById(200) as $deal) {
                $amount = $this->access->allows($org, $actor, $deal->pipeline, 'amount', $deal->assigned_to);
                $cells = [$deal->id, $deal->title, $deal->category, $deal->pipeline->name, $deal->stage->name, $deal->assignee?->name, $deal->email, $deal->phone, $amount ? $deal->amount : null, $amount ? $deal->currency : null];
                fputcsv($stream, array_map(fn ($cell) => is_string($cell) && preg_match('/^[\s]*[=+\-@]/u', $cell) ? "'".$cell : $cell, $cells), ',', '"', '');
            }
            fclose($stream);
        }, 'deals.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }
}
