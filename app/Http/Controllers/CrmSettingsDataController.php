<?php

namespace App\Http\Controllers;

use App\Domain\Crm\Actions\ManageCrmSettings;
use App\Domain\Crm\Actions\ManageCustomFields;
use App\Domain\Crm\Actions\ManageRecordFields;
use App\Domain\Crm\Actions\ManageWorkingCalendar;
use App\Domain\Crm\Models\CustomField;
use App\Domain\Crm\Models\SelectionOption;
use App\Domain\Crm\Services\DealAccess;
use App\Domain\Identity\Enums\OrganizationPermission;
use App\Domain\Identity\Enums\OrganizationRole;
use App\Models\CrmAccount;
use App\Models\CrmContact;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CrmSettingsDataController extends Controller
{
    public function index(Request $request, DealAccess $access): JsonResponse
    {
        $org = $this->organization($request);
        $this->authorize('viewCrm', $org);
        $configure = $access->administrator($org, $request->user());

        return response()->json(['lists' => ManageCrmSettings::LISTS, 'options' => SelectionOption::where('organization_id', $org->id)->when(! $configure, fn ($q) => $q->where('active', true))->orderBy('position')->orderBy('id')->get(), 'canConfigure' => $configure,
            'sectionPermissions' => array_column(OrganizationPermission::cases(), 'value'), 'roles' => array_column(OrganizationRole::cases(), 'value'),
            'sectionAccess' => $configure ? DB::table('organization_section_access')->where('organization_id', $org->id)->get() : [],
            'members' => $configure ? $org->users()->orderBy('name')->get(['users.id', 'users.name'])->map->only(['id', 'name']) : [],
            'departments' => $configure ? DB::table('crm_departments')->where('organization_id', $org->id)->get() : [],
            'subdepartments' => $configure ? DB::table('crm_subdepartments')->where('organization_id', $org->id)->get() : [],
            'teams' => $configure ? DB::table('crm_teams')->where('organization_id', $org->id)->get() : [],
        ]);
    }

    public function option(Request $request, ManageCrmSettings $manage, ?int $option = null): JsonResponse
    {
        return response()->json(['option' => $manage->option($this->organization($request), $request->user(), $request->all(), $option)]);
    }

    public function calendar(Request $request): JsonResponse
    {
        $org = $this->organization($request);
        $this->authorize('viewCrm', $org);
        $calendar = DB::table('crm_working_calendars')->where('organization_id', $org->id)->first();

        return response()->json(['calendar' => $calendar ? ['working_days' => json_decode($calendar->working_days, true), 'holidays' => json_decode($calendar->holidays, true), 'timezone' => $org->timezone, 'version' => $calendar->version, 'permissions' => ['read' => true, 'edit' => app(DealAccess::class)->administrator($org, $request->user())]] : null]);
    }

    public function saveCalendar(Request $request, ManageWorkingCalendar $calendar): JsonResponse
    {
        $version = $calendar->save($this->organization($request), $request->user(), $request->all());

        return response()->json(['saved' => true, 'version' => $version, 'permissions' => ['read' => true, 'edit' => true]]);
    }

    public function section(Request $request, ManageCrmSettings $manage): JsonResponse
    {
        $manage->section($this->organization($request), $request->user(), $request->all());

        return response()->json(['saved' => true]);
    }

    public function fields(Request $request, DealAccess $access): JsonResponse
    {
        $org = $this->organization($request);
        abort_unless($access->administrator($org, $request->user()), 403);
        $entity = $request->validate(['entity' => ['sometimes', 'in:lead,deal,contact,company']])['entity'] ?? 'lead';

        return response()->json(['fields' => CustomField::where('organization_id', $org->id)->where('entity', $entity)->orderBy('sort_order')->get(), 'types' => ManageCustomFields::TYPES, 'entity' => $entity]);
    }

    public function saveField(Request $request, ManageCustomFields $fields, ?int $field = null): JsonResponse
    {
        $org = $this->organization($request);
        $record = $field ? CustomField::where('organization_id', $org->id)->findOrFail($field) : null;

        return response()->json(['field' => $fields->save($org, $request->user(), $request->all(), $record)]);
    }

    public function recordFields(Request $request, string $entity, int $record, ManageRecordFields $fields): JsonResponse
    {
        return $this->handleRecordFields($request, $entity, $record, $fields, false);
    }

    public function updateRecordFields(Request $request, string $entity, int $record, ManageRecordFields $fields): JsonResponse
    {
        return $this->handleRecordFields($request, $entity, $record, $fields, true);
    }

    private function handleRecordFields(Request $request, string $entity, int $record, ManageRecordFields $fields, bool $writing): JsonResponse
    {
        $org = $this->organization($request);
        $this->authorize('viewCrm', $org);
        $model = match ($entity) {
            'contact' => CrmContact::class, 'company' => CrmAccount::class, default => abort(404)
        };
        $subject = $model::where('organization_id', $org->id)->findOrFail($record);
        if ($writing) {
            $this->authorize('manageCrm', $org);
            $input = $request->validate(['custom_fields' => ['required', 'array'], 'expected_version' => ['required', 'integer', 'min:1']]);
            DB::transaction(function () use ($org, $subject, $fields, $request, $input): void {
                Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
                $locked = $subject->newQuery()->whereKey($subject->id)->lockForUpdate()->firstOrFail();
                if ($locked->version !== $input['expected_version']) {
                    throw ValidationException::withMessages(['expected_version' => 'This record changed. Reload it and try again.']);
                }
                $fields->write($org, $request->user(), $subject, $input['custom_fields']);
                $locked->version++;
                $locked->save();
                $subject->version = $locked->version;
            });
        }

        return response()->json(['fields' => $fields->values($org, $request->user(), $subject), 'version' => $subject->version]);
    }

    private function organization(Request $request): Organization
    {
        $org = $request->user()->currentOrganization;
        abort_unless($org !== null, 404);

        return $org;
    }
}
