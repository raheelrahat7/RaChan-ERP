<?php

namespace App\Http\Controllers;

use App\Domain\Accounting\Queries\FinanceAuditTrail;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FinanceAuditTrailController extends Controller
{
    public function index(Request $request, FinanceAuditTrail $trail): Response
    {
        [$organization, $filters, $events, $actors] = $this->context($request);
        $logs = $trail->query($organization, $filters['from'] ?? null, $filters['to'] ?? null, $filters['event'] ?? null, isset($filters['actor_id']) ? (int) $filters['actor_id'] : null)
            ->with('actor:id,name,email')->latest()->paginate(50)->withQueryString();

        return Inertia::render('finance/FinanceAuditTrail', ['filters' => ['from' => $filters['from'] ?? '', 'to' => $filters['to'] ?? '', 'event' => $filters['event'] ?? '', 'actor_id' => isset($filters['actor_id']) ? (string) $filters['actor_id'] : ''], 'events' => $events, 'actors' => $actors, 'logs' => $logs]);
    }

    public function export(Request $request, FinanceAuditTrail $trail): StreamedResponse
    {
        [$organization, $filters] = $this->context($request);
        $logs = $trail->query($organization, $filters['from'] ?? null, $filters['to'] ?? null, $filters['event'] ?? null, isset($filters['actor_id']) ? (int) $filters['actor_id'] : null)->with('actor:id,name,email')->latest()->get();

        return response()->streamDownload(function () use ($logs): void {
            $output = fopen('php://output', 'w');
            if ($output === false) {
                throw new \RuntimeException('Unable to open CSV output stream.');
            }
            fputcsv($output, ['Timestamp', 'Event', 'Actor', 'Actor email', 'Subject type', 'Subject ID', 'Properties']);
            foreach ($logs as $log) {
                fputcsv($output, [$log->created_at->toIso8601String(), $log->event, $this->safeCell($log->actor?->name), $this->safeCell($log->actor?->email), $this->safeCell($log->subject_type), $log->subject_id, $this->safeCell(json_encode($log->properties ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR))]);
            }
            fclose($output);
        }, 'finance-audit-trail.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @return array{Organization, array<string, mixed>, Collection<int, string>, \Illuminate\Database\Eloquent\Collection<int, User>}
     */
    private function context(Request $request): array
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization !== null, 404);
        $this->authorize('viewFinance', $organization);
        $events = AuditLog::where('organization_id', $organization->id)->where(fn ($query) => $query->where('event', 'like', 'accounting.%')->orWhere('event', 'like', 'finance.%'))->distinct()->orderBy('event')->pluck('event');
        $actors = $organization->users()->select('users.id', 'users.name', 'users.email')->orderBy('users.name')->get();
        $filters = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'], 'event' => ['nullable', 'string', Rule::in($events)], 'actor_id' => ['nullable', 'integer', Rule::in($actors->pluck('id'))]]);

        return [$organization, $filters, $events, $actors];
    }

    private function safeCell(?string $value): string
    {
        $value ??= '';

        return preg_match('/^[\s]*[=+\-@]/u', $value) ? "'".$value : $value;
    }
}
