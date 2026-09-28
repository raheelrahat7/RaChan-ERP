<?php

namespace App\Http\Controllers;

use App\Domain\Accounting\Queries\JournalRegister;
use App\Models\JournalEntry;
use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class JournalRegisterController extends Controller
{
    public function index(Request $request, JournalRegister $register): Response
    {
        [$organization, $filters] = $this->context($request);

        $entries = $register->query($organization, $filters['from'] ?? null, $filters['to'] ?? null, $filters['event'] ?? null)
            ->with('lines.account:id,code,name')->orderBy('posted_on')->orderBy('id')->paginate(50)->withQueryString();

        return Inertia::render('finance/JournalRegister', [
            'filters' => ['from' => $filters['from'] ?? '', 'to' => $filters['to'] ?? '', 'event' => $filters['event'] ?? ''],
            'events' => JournalEntry::where('organization_id', $organization->id)->where('currency', 'AED')->whereHas('lines')->distinct()->orderBy('event')->pluck('event'),
            'entries' => $entries,
        ]);
    }

    public function export(Request $request, JournalRegister $register): StreamedResponse
    {
        [$organization, $filters] = $this->context($request);
        $entries = $register->query($organization, $filters['from'] ?? null, $filters['to'] ?? null, $filters['event'] ?? null)
            ->with('lines.account:id,code,name')->orderBy('posted_on')->orderBy('id')->get();

        return response()->streamDownload(function () use ($entries): void {
            $output = fopen('php://output', 'w');
            if ($output === false) {
                throw new \RuntimeException('Unable to open CSV output stream.');
            }
            fputcsv($output, ['Date', 'Journal', 'Source reference', 'Event', 'Account code', 'Account', 'Description', 'Debit AED', 'Credit AED', 'Reversal of']);
            foreach ($entries as $entry) {
                foreach ($entry->lines as $line) {
                    fputcsv($output, [$entry->posted_on->toDateString(), $entry->reference, $this->safeCell($entry->source_reference), $entry->event, $line->account?->code, $this->safeCell($line->account?->name), $this->safeCell($line->description), $line->debit, $line->credit, $entry->reversal_of_id]);
                }
            }
            fclose($output);
        }, 'journal-register-aed.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return array{0: Organization, 1: array<string, mixed>} */
    private function context(Request $request): array
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization !== null, 404);
        $this->authorize('viewFinance', $organization);
        $events = JournalEntry::where('organization_id', $organization->id)->where('currency', 'AED')->whereHas('lines')->distinct()->pluck('event');
        $filters = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'event' => ['nullable', 'string', Rule::in($events)],
        ]);

        return [$organization, $filters];
    }

    private function safeCell(?string $value): string
    {
        $value ??= '';

        return preg_match('/^[\s]*[=+\-@]/u', $value) ? "'".$value : $value;
    }
}
