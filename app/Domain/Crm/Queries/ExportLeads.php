<?php

namespace App\Domain\Crm\Queries;

use App\Domain\Crm\Actions\ManageCustomFields;
use App\Domain\Crm\Models\CustomFieldValue;
use App\Domain\Crm\Services\LeadVisibility;
use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Domain\Identity\Enums\OrganizationRole;
use App\Models\CrmActivity;
use App\Models\CrmLead;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportLeads
{
    private const COLUMNS = [
        'id' => 'Lead ID', 'first_name' => 'First name', 'last_name' => 'Last name',
        'email' => 'Email', 'phone' => 'Phone number', 'company' => 'Company',
        'city' => 'City', 'source' => 'Source', 'status' => 'Status',
        'stage' => 'Stage', 'pipeline' => 'Pipeline', 'assignee' => 'Responsible person',
        'project_name' => 'Project name', 'campaign_name' => 'Campaign name',
        'notes' => 'Lead notes', 'activity_notes' => 'Activity notes (latest 20)',
        'created_at' => 'Created on', 'updated_at' => 'Modified on',
    ];

    public function __construct(private ManageCustomFields $fields, private RecordOrganizationAuditLog $audit, private LeadVisibility $visibility) {}

    public function canExport(Organization $org, User $actor): bool
    {
        return $actor->belongsToOrganization($org) && (
            $actor->hasOrganizationRole($org, OrganizationRole::Owner) ||
            $actor->hasOrganizationRole($org, OrganizationRole::Administrator) ||
            DB::table('crm_lead_export_grants')->where('organization_id', $org->id)->where('user_id', $actor->id)->exists()
        );
    }

    public function authorize(Organization $org, User $actor): void
    {
        abort_unless($this->canExport($org, $actor), 403);
    }

    /** @return list<array{key:string,label:string,group:string}> */
    public function columns(Organization $org, User $actor): array
    {
        $this->authorize($org, $actor);
        $items = [];
        foreach (self::COLUMNS as $key => $label) {
            $items[] = ['key' => $key, 'label' => $label, 'group' => $key === 'activity_notes' ? 'Activity' : 'Lead'];
        }
        foreach ($this->fields->visible($org, $actor) as $field) {
            $items[] = ['key' => 'custom:'.$field->key, 'label' => $field->name, 'group' => 'Custom fields'];
        }

        return $items;
    }

    /** @param list<string> $selected */
    public function download(Organization $org, User $actor, array $selected): StreamedResponse
    {
        $allowed = collect($this->columns($org, $actor))->pluck('label', 'key');
        if ($selected === [] || count($selected) > 100 || count($selected) !== count(array_unique($selected)) || array_diff($selected, $allowed->keys()->all())) {
            throw ValidationException::withMessages(['columns' => 'Select one or more permitted export fields without duplicates.']);
        }
        $custom = collect($this->fields->visible($org, $actor))->keyBy('key');
        $this->audit->handle($org, $actor, 'crm.leads.exported', $org, ['columns' => $selected]);

        return response()->streamDownload(function () use ($org, $actor, $selected, $allowed, $custom): void {
            $output = fopen('php://output', 'w');
            if ($output === false) {
                throw new \RuntimeException('Could not open export stream.');
            }
            $this->writeRow($output, array_map(fn (string $key) => $allowed[$key], $selected));
            $this->visibility->scope(CrmLead::where('organization_id', $org->id), $org, $actor)
                ->with(['stage:id,name', 'pipeline:id,name', 'assignee:id,name'])
                ->orderBy('id')->chunkById(200, function ($leads) use ($org, $selected, $custom, $output): void {
                    $ids = $leads->pluck('id');
                    $fieldIds = collect($selected)->filter(fn ($key) => str_starts_with($key, 'custom:'))
                        ->map(fn ($key) => $custom->get(substr($key, 7))?->id)->filter()->values();
                    $values = CustomFieldValue::where('organization_id', $org->id)
                        ->whereIn('lead_id', $ids)->whereIn('field_id', $fieldIds)->get()->groupBy('lead_id');
                    $recentActivities = DB::table('crm_activities')->where('organization_id', $org->id)
                        ->where('subject_type', CrmLead::class)->whereIn('subject_id', $ids)->whereNotNull('notes')
                        ->select('subject_id', 'notes')->selectRaw('ROW_NUMBER() OVER (PARTITION BY subject_id ORDER BY id DESC) AS activity_rank');
                    $activities = in_array('activity_notes', $selected, true)
                        ? DB::query()->fromSub($recentActivities, 'recent')->where('activity_rank', '<=', 20)
                            ->orderBy('subject_id')->orderBy('activity_rank')->get(['subject_id', 'notes'])
                            ->groupBy('subject_id')->map(fn ($rows) => $rows->pluck('notes')->implode(' | '))
                        : collect();
                    foreach ($leads as $lead) {
                        $fieldValues = $values->get($lead->id)?->keyBy('field_id');
                        $row = [];
                        foreach ($selected as $key) {
                            $value = match ($key) {
                                'stage' => $lead->stage?->name,
                                'pipeline' => $lead->pipeline?->name,
                                'assignee' => $lead->assignee?->name,
                                'activity_notes' => $activities->get($lead->id),
                                default => str_starts_with($key, 'custom:')
                                    ? $fieldValues?->get($custom->get(substr($key, 7))?->id)?->value
                                    : $lead->{$key},
                            };
                            $row[] = is_array($value) ? implode(' | ', $value) : $value;
                        }
                        $this->writeRow($output, $row);
                    }
                });
            fclose($output);
        }, 'crm-leads-'.now()->format('Y-m-d-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function downloadActivities(Organization $org, User $actor, ?int $leadId = null): StreamedResponse
    {
        $this->authorize($org, $actor);
        if ($leadId !== null) {
            $visibleLead = $this->visibility->scope(CrmLead::where('organization_id', $org->id), $org, $actor)
                ->whereKey($leadId)->exists();
            abort_unless($visibleLead, 404);
        }
        $this->audit->handle($org, $actor, 'crm.lead_activities.exported', $org, ['lead_id' => $leadId]);

        return response()->streamDownload(function () use ($org, $actor, $leadId): void {
            $output = fopen('php://output', 'w');
            if ($output === false) {
                throw new \RuntimeException('Could not open export stream.');
            }
            $this->writeRow($output, ['Lead ID', 'Lead name', 'Activity type', 'Comment', 'Created by', 'Created at', 'Due at', 'Completed at']);
            $leads = $this->visibility->scope(CrmLead::where('organization_id', $org->id), $org, $actor)->select('id');
            CrmActivity::where('organization_id', $org->id)->where('subject_type', CrmLead::class)
                ->whereIn('subject_id', $leads)->when($leadId !== null, fn ($query) => $query->where('subject_id', $leadId))
                ->with(['subject:id,first_name,last_name', 'creator:id,name'])
                ->orderBy('id')->chunkById(200, function ($activities) use ($output): void {
                    foreach ($activities as $activity) {
                        $lead = $activity->subject;
                        $this->writeRow($output, [
                            $activity->subject_id,
                            $lead instanceof CrmLead ? trim($lead->first_name.' '.$lead->last_name) : '',
                            $activity->type, $activity->notes, $activity->creator?->name,
                            $activity->created_at, $activity->due_at, $activity->completed_at,
                        ]);
                    }
                });
            fclose($output);
        }, 'crm-lead-activities-'.now()->format('Y-m-d-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @param resource $output
     * @param  list<mixed>  $values
     */
    private function writeRow($output, array $values): void
    {
        fputcsv($output, array_map(function ($value): string {
            $text = (string) ($value ?? '');

            return preg_match('/^[\s]*[=+\-@]/u', $text) ? "'".$text : $text;
        }, $values), ',', '"', '');
    }
}
