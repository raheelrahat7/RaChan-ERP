<?php

namespace App\Domain\Crm\Actions;

use App\Domain\Crm\Models\SelectionOption;
use App\Domain\Crm\Services\DealAccess;
use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Domain\Identity\Enums\OrganizationPermission;
use App\Domain\Identity\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ManageCrmSettings
{
    public const LISTS = ['sources', 'contact_types', 'company_types', 'company_sizes', 'industries', 'deal_types', 'salutations', 'call_statuses', 'deal_categories'];

    public function __construct(private DealAccess $access, private RecordOrganizationAuditLog $audit) {}

    /** @param array<string, mixed> $input */
    public function option(Organization $org, User $actor, array $input, ?int $id = null): SelectionOption
    {
        abort_unless($this->access->administrator($org, $actor), 403);
        $data = Validator::make($input, ['list_key' => ['required', Rule::in(self::LISTS)], 'name' => ['required', 'string', 'max:120'], 'position' => ['required', 'integer', 'between:0,10000'], 'code' => ['sometimes', 'string', 'max:24', 'regex:/^[a-z][a-z0-9_]*$/'], 'active' => ['required', 'boolean']])->validate();

        return DB::transaction(function () use ($org, $actor, $data, $id): SelectionOption {
            Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
            if ($data['list_key'] === 'deal_categories' && ! SelectionOption::where('organization_id', $org->id)->where('list_key', 'deal_categories')->exists()) {
                foreach (ManageDeals::CATEGORIES as $position => $code) {
                    SelectionOption::create(['organization_id' => $org->id, 'list_key' => 'deal_categories', 'code' => $code, 'name' => ucfirst($code), 'position' => $position, 'active' => true]);
                }
            }
            $option = $id ? SelectionOption::where('organization_id', $org->id)->findOrFail($id) : new SelectionOption(['organization_id' => $org->id]);
            if ($id && $option->list_key !== $data['list_key']) {
                throw ValidationException::withMessages(['list_key' => 'An option cannot move to another list.']);
            }
            if (SelectionOption::where('organization_id', $org->id)->where('list_key', $data['list_key'])->where('name', $data['name'])->whereKeyNot($id ?? 0)->exists()) {
                throw ValidationException::withMessages(['name' => 'This option already exists.']);
            }
            if ($data['list_key'] === 'deal_categories') {
                $code = $id ? $option->code : ($data['code'] ?? null);
                if (! $code || ($id && isset($data['code']) && $data['code'] !== $code) || SelectionOption::where('organization_id', $org->id)->where('list_key', 'deal_categories')->where('code', $code)->whereKeyNot($id ?? 0)->exists()) {
                    throw ValidationException::withMessages(['code' => 'Provide a unique category code; existing codes cannot change.']);
                }
                $data['code'] = $code;
            } else {
                unset($data['code']);
            }
            $option->fill($data)->save();
            $this->audit->handle($org, $actor, 'crm.selection_option.saved', $option, $data);

            return $option;
        });
    }

    /** @return list<array{code: string, name: string, active: bool, id: int|null}> */
    public function categories(Organization $org): array
    {
        $options = SelectionOption::where('organization_id', $org->id)->where('list_key', 'deal_categories')->orderBy('position')->orderBy('id')->get();
        if ($options->isEmpty()) {
            return array_map(fn (string $code) => ['code' => $code, 'name' => ucfirst($code), 'active' => true, 'id' => null], ManageDeals::CATEGORIES);
        }

        return array_values($options->map(fn (SelectionOption $option) => ['code' => (string) $option->code, 'name' => $option->name, 'active' => $option->active, 'id' => $option->id])->all());
    }

    /** @return list<string> */
    public function activeCategories(Organization $org): array
    {
        return array_column(array_filter($this->categories($org), fn ($category) => $category['active']), 'code');
    }

    /** @param array<string, mixed> $input */
    public function section(Organization $org, User $actor, array $input): void
    {
        abort_unless($this->access->administrator($org, $actor), 403);
        $data = Validator::make($input, ['principal_type' => ['required', Rule::in(['role', 'user', 'team', 'subdepartment', 'department'])], 'principal_id' => ['required', 'string', 'max:40'], 'permission' => ['required', Rule::in(array_column(OrganizationPermission::cases(), 'value'))], 'enabled' => ['required', 'boolean']])->validate();
        $valid = match ($data['principal_type']) {
            'role' => in_array($data['principal_id'], array_column(OrganizationRole::cases(), 'value'), true),
            'user' => ctype_digit($data['principal_id']) && $org->users()->where('users.id', $data['principal_id'])->exists(),
            default => ctype_digit($data['principal_id']) && DB::table('crm_'.match ($data['principal_type']) {
                'team' => 'teams', 'department' => 'departments', default => 'subdepartments'
            })->where('organization_id', $org->id)->where('id', $data['principal_id'])->exists(),
        };
        if (! $valid) {
            throw ValidationException::withMessages(['principal_id' => 'Select an organization role, member or group.']);
        }
        DB::transaction(function () use ($org, $actor, $data): void {
            Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
            DB::table('organization_section_access')->updateOrInsert(['organization_id' => $org->id, 'principal_type' => $data['principal_type'], 'principal_id' => $data['principal_id'], 'permission' => $data['permission']], ['enabled' => $data['enabled'], 'created_at' => now(), 'updated_at' => now()]);
            $this->audit->handle($org, $actor, 'identity.section_access.saved', null, $data);
        });
    }
}
