<?php

namespace App\Http\Controllers;

use App\Domain\Accounting\Actions\ManageLegacyJournalMapping;
use App\Domain\Accounting\Models\JournalMappingApprover;
use App\Domain\Accounting\Models\LedgerAccount;
use App\Domain\Accounting\Models\LegacyJournalMapping;
use App\Domain\Identity\Enums\OrganizationRole;
use App\Models\JournalEntry;
use App\Models\Organization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LegacyJournalMappingController extends Controller
{
    public function index(Request $request, ManageLegacyJournalMapping $workflow): Response
    {
        $organization = $this->organization($request);
        $this->authorize('viewFinance', $organization);

        return Inertia::render('finance/LegacyJournalMappings', [
            'summaries' => JournalEntry::where('organization_id', $organization->id)->whereDoesntHave('lines')->orderByDesc('posted_on')->get(['id', 'reference', 'posted_on', 'currency', 'event', 'debit_total', 'credit_total']),
            'mappings' => LegacyJournalMapping::where('organization_id', $organization->id)->with(['journal:id,reference', 'submitter:id,name', 'approver:id,name'])->latest('id')->get(),
            'accounts' => LedgerAccount::where('organization_id', $organization->id)->orderBy('code')->get(['id', 'code', 'name', 'is_active']),
            'members' => $organization->users()->orderBy('name')->get(['users.id', 'users.name'])->map(fn ($member): array => ['id' => $member->id, 'name' => $member->name, 'is_owner' => $member->pivot->getAttribute('role') === OrganizationRole::Owner->value]),
            'delegates' => JournalMappingApprover::where('organization_id', $organization->id)->whereNull('revoked_at')->pluck('user_id'),
            'canDelegate' => $request->user()->hasOrganizationRole($organization, OrganizationRole::Owner),
            'canApprove' => $workflow->canApprove($organization, $request->user()),
            'canSubmit' => $request->user()->can('manageFinance', $organization),
            'actorId' => $request->user()->id,
        ]);
    }

    public function delegate(Request $request, ManageLegacyJournalMapping $workflow): RedirectResponse
    {
        $input = $request->validate(['user_id' => 'required|integer', 'allowed' => 'required|boolean']);
        $workflow->delegate($this->organization($request), $request->user(), (int) $input['user_id'], $input['allowed']);

        return back();
    }

    public function store(Request $request, ManageLegacyJournalMapping $workflow): RedirectResponse
    {
        $organization = $this->organization($request);
        $this->authorize('manageFinance', $organization);
        $input = $request->validate(['journal_entry_id' => 'required|integer', 'reason' => 'required|string|max:2000', 'evidence_reference' => 'required|string|max:255', 'lines' => 'required|array']);
        $source = JournalEntry::where('organization_id', $organization->id)->findOrFail((int) $input['journal_entry_id']);
        $workflow->submit($organization, $request->user(), $source, $input['lines'], $input['reason'], $input['evidence_reference']);

        return back();
    }

    public function decide(Request $request, LegacyJournalMapping $mapping, ManageLegacyJournalMapping $workflow): RedirectResponse
    {
        $organization = $this->organization($request);
        abort_unless($mapping->organization_id === $organization->id, 404);
        $input = $request->validate(['approve' => 'required|boolean', 'reason' => 'required|string|max:2000']);
        $workflow->decide($organization, $request->user(), $mapping, $input['approve'], $input['reason']);

        return back();
    }

    public function reverse(Request $request, LegacyJournalMapping $mapping, ManageLegacyJournalMapping $workflow): RedirectResponse
    {
        $organization = $this->organization($request);
        abort_unless($mapping->organization_id === $organization->id, 404);
        $input = $request->validate(['posted_on' => 'required|date_format:Y-m-d', 'reason' => 'required|string|max:2000']);
        $workflow->reverse($organization, $request->user(), $mapping, $input['posted_on'], $input['reason']);

        return back();
    }

    private function organization(Request $request): Organization
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization !== null, 404);

        return $organization;
    }
}
