<?php

namespace App\Domain\RealEstate\Actions;

use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ManagePropertyParties
{
    public function __construct(private RecordOrganizationAuditLog $audit) {}

    public function table(string $type): string
    {
        return match ($type) {
            'owners' => 'owners',
            'developers' => 'offplan_developers',
            default => abort(404),
        };
    }

    /** @return array<string, mixed> */
    public function serialize(Organization $org, User $actor, string $type, object $record, bool $withLinks = false): array
    {
        $values = (array) $record;
        $values['version'] = (int) $values['version'];
        $values['permissions'] = ['read' => true, 'edit' => $actor->can('manageCrm', $org)];
        if ($withLinks) {
            $field = $type === 'owners' ? 'owner_id' : 'developer_id';
            $values['listings'] = DB::table('listings')->where('organization_id', $org->id)->where($field, $values['id'])
                ->orderByDesc('id')->get(['id', 'reference', 'purpose', 'status', 'price', 'currency']);
            $values['offplan_projects'] = $type === 'developers'
                ? DB::table('offplan_projects')->where('organization_id', $org->id)->where('developer_id', $values['id'])
                    ->orderBy('name')->get(['id', 'code', 'name', 'workflow_status'])
                : [];
        }

        return $values;
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function validateDetails(array $input, bool $create): array
    {
        return Validator::make($input, [
            'name' => [$create ? 'required' : 'sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'reference' => ['sometimes', 'nullable', 'string', 'max:100'],
            'payment_terms' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'commission_notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ])->validate();
    }

    /** @param array<string, mixed> $input */
    public function create(Organization $org, User $actor, string $type, array $input): object
    {
        abort_unless($actor->can('manageCrm', $org), 403);
        $table = $this->table($type);
        $data = $this->validateDetails($input, true);

        return DB::transaction(function () use ($org, $actor, $table, $type, $data): object {
            if ($type === 'developers' && DB::table($table)->where('organization_id', $org->id)->where('name', $data['name'])->exists()) {
                throw ValidationException::withMessages(['name' => 'This developer name already exists.']);
            }
            $id = DB::table($table)->insertGetId(['organization_id' => $org->id, ...$data, 'created_at' => now(), 'updated_at' => now()]);
            $this->audit->handle($org, $actor, 'real_estate.party.created', $org, ['type' => $type, 'id' => $id]);

            return DB::table($table)->where('id', $id)->first();
        });
    }

    /** @param array<string, mixed> $input */
    public function update(Organization $org, User $actor, string $type, int $id, array $input): object
    {
        abort_unless($actor->can('manageCrm', $org), 403);
        $table = $this->table($type);
        $version = Validator::make($input, ['expected_version' => ['required', 'integer', 'min:1']])->validate()['expected_version'];
        $data = $this->validateDetails($input, false);

        return DB::transaction(function () use ($org, $actor, $table, $type, $id, $version, $data): object {
            $record = DB::table($table)->where('organization_id', $org->id)->where('id', $id)->lockForUpdate()->first();
            abort_unless($record !== null, 404);
            if ($record->version !== (int) $version) {
                throw ValidationException::withMessages(['expected_version' => 'This record changed. Refresh before saving.']);
            }
            if ($type === 'developers' && isset($data['name']) && DB::table($table)->where('organization_id', $org->id)->where('name', $data['name'])->where('id', '!=', $id)->exists()) {
                throw ValidationException::withMessages(['name' => 'This developer name already exists.']);
            }
            DB::table($table)->where('id', $id)->update([...$data, 'version' => $record->version + 1, 'updated_at' => now()]);
            $this->audit->handle($org, $actor, 'real_estate.party.updated', $org, ['type' => $type, 'id' => $id, 'changes' => $data]);

            return DB::table($table)->where('id', $id)->first();
        });
    }
}
