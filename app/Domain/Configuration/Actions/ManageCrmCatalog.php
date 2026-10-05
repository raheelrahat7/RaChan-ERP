<?php

namespace App\Domain\Configuration\Actions;

use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ManageCrmCatalog
{
    public const KINDS = ['taxes', 'units', 'detail-templates', 'company-details', 'mailboxes', 'products'];

    /** @return array<string, mixed> */
    public function index(Organization $org, User $actor, string $kind): array
    {
        $this->authorize($org, $actor, $kind);

        return ['kind' => $kind, 'records' => DB::table('organization_crm_settings')
            ->where('organization_id', $org->id)->where('kind', $kind)
            ->orderBy('position')->orderBy('id')->get()->map(function ($row) {
                $row->settings = json_decode($row->settings ?? '{}', true);

                return $row;
            })];
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function save(Organization $org, User $actor, string $kind, array $input, ?int $id = null): array
    {
        $this->authorize($org, $actor, $kind);
        $common = ['code' => ['required', 'regex:/^[a-z][a-z0-9_-]{0,99}$/'], 'name' => ['required', 'string', 'max:150'], 'active' => ['required', 'boolean'], 'position' => ['sometimes', 'integer', 'between:0,100000']];
        $settings = match ($kind) {
            'taxes' => ['rate' => ['required', 'numeric', 'between:0,100'], 'description' => ['nullable', 'string', 'max:500']],
            'units' => ['symbol' => ['required', 'string', 'max:20'], 'precision' => ['required', 'integer', 'between:0,4']],
            'detail-templates' => ['entity' => ['required', 'in:contact,company'], 'fields' => ['required', 'array', 'min:1', 'max:100'], 'fields.*' => ['required', 'string', 'max:100']],
            'company-details' => ['legal_name' => ['required', 'string', 'max:200'], 'address' => ['nullable', 'string', 'max:1000'], 'phone' => ['nullable', 'string', 'max:50'], 'email' => ['nullable', 'email', 'max:255'], 'tax_registration_number' => ['nullable', 'string', 'max:100']],
            'mailboxes' => ['email' => ['required', 'email', 'max:255'], 'display_name' => ['nullable', 'string', 'max:150'], 'reply_to' => ['nullable', 'email', 'max:255']],
            'products' => ['sku' => ['nullable', 'string', 'max:100'], 'unit_id' => ['nullable', 'integer'], 'tax_id' => ['nullable', 'integer'], 'price' => ['required', 'numeric', 'min:0', 'max:999999999999.99'], 'currency' => ['required', 'regex:/^[A-Z]{3}$/']],
            default => abort(404),
        };
        $data = Validator::make($input, [...$common, 'settings' => ['required', 'array:'.implode(',', array_keys($settings))], ...collect($settings)->mapWithKeys(fn ($rules, $field) => ['settings.'.$field => $rules])->all()])->validate();

        return DB::transaction(function () use ($org, $actor, $kind, $data, $id): array {
            Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
            $existing = $id ? DB::table('organization_crm_settings')->where('organization_id', $org->id)->where('kind', $kind)->where('id', $id)->first() : null;
            abort_if($id && ! $existing, 404);
            if ($existing && $existing->code !== $data['code']) {
                throw ValidationException::withMessages(['code' => 'The code cannot change after creation.']);
            }
            if (DB::table('organization_crm_settings')->where('organization_id', $org->id)->where('kind', $kind)->where('code', $data['code'])->where('id', '!=', $id ?? 0)->exists()) {
                throw ValidationException::withMessages(['code' => 'This code already exists.']);
            }
            if ($kind === 'products') {
                foreach (['unit_id' => 'units', 'tax_id' => 'taxes'] as $field => $relatedKind) {
                    if (! empty($data['settings'][$field]) && ! DB::table('organization_crm_settings')->where('organization_id', $org->id)->where('kind', $relatedKind)->where('active', true)->where('id', $data['settings'][$field])->exists()) {
                        throw ValidationException::withMessages(['settings.'.$field => 'Select an active organization '.$relatedKind.' record.']);
                    }
                }
            }
            $row = ['organization_id' => $org->id, 'kind' => $kind, 'code' => $data['code'], 'name' => $data['name'], 'settings' => json_encode($data['settings']), 'active' => $data['active'], 'position' => $data['position'] ?? 100, 'updated_at' => now()];
            if ($id) {
                DB::table('organization_crm_settings')->where('id', $id)->update($row);
            } else {
                $id = DB::table('organization_crm_settings')->insertGetId([...$row, 'created_at' => now()]);
            }
            app(RecordOrganizationAuditLog::class)->handle($org, $actor, 'configuration.crm_catalog.saved', null, ['kind' => $kind, 'id' => $id, 'code' => $data['code']]);

            return ['id' => $id, ...$row, 'settings' => $data['settings']];
        });
    }

    private function authorize(Organization $org, User $actor, string $kind): void
    {
        abort_unless(in_array($kind, self::KINDS, true), 404);
        Gate::forUser($actor)->authorize('manageSettings', $org);
    }
}
