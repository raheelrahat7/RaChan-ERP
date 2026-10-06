<?php

namespace App\Domain\Configuration\Actions;

use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Domain\Integrations\Services\LocalOutboundProvider;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ManageReferenceConfiguration
{
    public const TABLES = ['currencies' => 'organization_currencies', 'locations' => 'organization_locations', 'numbering' => 'organization_numbering_templates', 'providers' => 'organization_provider_profiles'];

    /** @return array<string, mixed> */
    public function index(Organization $org, User $actor, string $kind): array
    {
        abort_unless(isset(self::TABLES[$kind]), 404);
        Gate::forUser($actor)->authorize('manageSettings', $org);
        $rows = DB::table(self::TABLES[$kind])->where('organization_id', $org->id)->orderBy('id')->get();
        foreach ($rows as $row) {
            if ($kind === 'providers') {
                $row->permissions = ['read' => true, 'edit' => true];
            }
            foreach (['translations', 'settings'] as $column) {
                if (isset($row->$column)) {
                    $row->$column = json_decode($row->$column, true);
                }
            }
        }

        return ['records' => $rows, 'kind' => $kind, 'providerCapabilities' => LocalOutboundProvider::CAPABILITIES, 'deliveryEnabled' => false];
    }

    /** @param array<string, mixed> $input */
    public function save(Organization $org, User $actor, string $kind, array $input, ?int $id = null): int
    {
        Gate::forUser($actor)->authorize('manageSettings', $org);
        abort_unless(isset(self::TABLES[$kind]), 404);
        $rules = match ($kind) {
            'currencies' => ['code' => ['required', 'regex:/^[A-Z]{3}$/'], 'name' => ['required', 'string', 'max:100'], 'exchange_rate' => ['required', 'regex:/^\d{1,14}(?:\.\d{1,10})?$/', 'numeric', 'gt:0'], 'face_value' => ['required', 'integer', 'between:1,1000000'], 'is_base' => ['required', 'boolean'], 'is_reporting' => ['required', 'boolean'], 'active' => ['required', 'boolean'], 'position' => ['sometimes', 'integer', 'between:0,10000']],
            'locations' => ['name' => ['required', 'string', 'max:120'], 'type' => ['required', 'in:country,region,city'], 'parent_id' => ['nullable', 'integer'], 'translations' => ['sometimes', 'array', 'max:30'], 'translations.*' => ['string', 'max:120'], 'active' => ['required', 'boolean'], 'position' => ['sometimes', 'integer', 'between:0,10000']],
            'numbering' => ['kind' => ['required', 'in:invoice,estimate,document,recruitment'], 'prefix' => ['required', 'regex:/^[A-Za-z0-9_-]{1,40}$/'], 'padding' => ['required', 'integer', 'between:1,12'], 'next_number' => ['required', 'integer', 'between:1,999999999999'], 'include_year' => ['required', 'boolean'], 'active' => ['required', 'boolean']],
            default => ['capability' => ['required', 'in:'.implode(',', LocalOutboundProvider::CAPABILITIES)], 'name' => ['required', 'string', 'max:100'], 'provider' => ['nullable', 'string', 'max:100'], 'active' => ['required', 'boolean'], 'settings' => ['sometimes', 'array:sender,label,print_title,terms,daily_limit'], 'settings.sender' => ['nullable', 'string', 'max:100'], 'settings.label' => ['nullable', 'string', 'max:100'], 'settings.print_title' => ['nullable', 'string', 'max:255'], 'settings.terms' => ['nullable', 'string', 'max:2000'], 'settings.daily_limit' => ['nullable', 'integer', 'between:0,100000']],
        };
        if ($kind === 'providers' && $id !== null) {
            $rules['expected_version'] = ['required', 'integer', 'min:1'];
        }
        $data = Validator::make($input, $rules)->validate();

        return DB::transaction(function () use ($org, $actor, $kind, $data, $id): int {
            Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
            $table = self::TABLES[$kind];
            $existing = $id ? DB::table($table)->where('organization_id', $org->id)->where('id', $id)->first() : null;
            abort_if($id && ! $existing, 404);
            if ($kind === 'providers' && $existing) {
                if ($existing->version !== (int) $data['expected_version']) {
                    $this->fail('expected_version', 'This provider profile changed. Reload it and try again.');
                }
                unset($data['expected_version']);
                $data['version'] = $existing->version + 1;
            }
            if ($kind === 'currencies') {
                if ($existing && $existing->code !== $data['code']) {
                    $this->fail('code', 'Currency codes cannot change. Rename the display name instead.');
                }
                if (DB::table($table)->where('organization_id', $org->id)->where('code', $data['code'])->where('id', '!=', $id ?? 0)->exists()) {
                    $this->fail('code', 'This currency already exists.');
                }
                if (($data['is_base'] || $data['is_reporting']) && ! $data['active']) {
                    $this->fail('active', 'Base and reporting currencies must be active.');
                }
                if ($data['is_base'] && ((string) $data['exchange_rate'] !== '1' && (float) $data['exchange_rate'] !== 1.0 || (int) $data['face_value'] !== 1)) {
                    $this->fail('exchange_rate', 'Base currency rate and face value must be one.');
                }
                $base = DB::table($table)->where('organization_id', $org->id)->where('is_base', true)->first();
                if ($data['is_base'] && $base && $base->id !== $id) {
                    $this->fail('is_base', 'Use the currency rebase operation to supply all rates relative to the new base.');
                }
                foreach (['is_base', 'is_reporting'] as $flag) {
                    if ($existing && $existing->$flag && ! $data[$flag]) {
                        $this->fail($flag, 'Select another '.$flag.' currency first.');
                    }
                    if ($data[$flag]) {
                        DB::table($table)->where('organization_id', $org->id)->update([$flag => false]);
                    }
                }
            } elseif ($kind === 'locations') {
                $parent = isset($data['parent_id']) ? DB::table($table)->where('organization_id', $org->id)->where('id', $data['parent_id'])->where('active', true)->first() : null;
                $expected = match ($data['type']) {
                    'region' => 'country', 'city' => 'region', default => null
                };
                if ((isset($data['parent_id']) && ! $parent) || ($expected && (! $parent || $parent->type !== $expected)) || (! $expected && $parent)) {
                    $this->fail('parent_id', 'Regions belong to countries; cities belong to regions.');
                }
                if ($existing && $existing->type !== $data['type'] && DB::table($table)->where('parent_id', $id)->exists()) {
                    $this->fail('type', 'A location with children cannot change type.');
                }
                if (! $data['active'] && DB::table($table)->where('parent_id', $id ?? 0)->where('active', true)->exists()) {
                    $this->fail('active', 'Archive active child locations first.');
                }
            } elseif ($kind === 'numbering') {
                if ($existing && ($existing->kind !== $data['kind'] || $data['next_number'] < $existing->next_number)) {
                    $this->fail('next_number', 'Numbering kinds are immutable and counters cannot move backwards.');
                }
                if (DB::table($table)->where('organization_id', $org->id)->where('kind', $data['kind'])->where('id', '!=', $id ?? 0)->exists()) {
                    $this->fail('kind', 'Edit the existing numbering template.');
                }
            } elseif ($existing && $existing->capability !== $data['capability']) {
                $this->fail('capability', 'A provider profile cannot change capability.');
            } elseif ($data['active']) {
                $this->fail('active', 'Providers remain unselected. Local profiles cannot enable delivery.');
            }
            foreach (['translations', 'settings'] as $column) {
                if (isset($data[$column])) {
                    $data[$column] = json_encode($data[$column], JSON_THROW_ON_ERROR);
                }
            }
            if ($existing) {
                DB::table($table)->where('id', $id)->update([...$data, 'updated_at' => now()]);
            } else {
                $id = DB::table($table)->insertGetId(['organization_id' => $org->id, ...$data, 'created_at' => now(), 'updated_at' => now()]);
            }
            app(RecordOrganizationAuditLog::class)->handle($org, $actor, 'configuration.'.$kind.'.saved', null, ['id' => $id]);

            return $id;
        });
    }

    /** @param array<string, mixed> $input */
    public function rebase(Organization $org, User $actor, array $input): void
    {
        Gate::forUser($actor)->authorize('manageSettings', $org);
        $data = Validator::make($input, ['base_code' => ['required', 'regex:/^[A-Z]{3}$/'], 'rates' => ['required', 'array', 'min:1'], 'rates.*' => ['array:code,exchange_rate,face_value'], 'rates.*.code' => ['required', 'regex:/^[A-Z]{3}$/', 'distinct'], 'rates.*.exchange_rate' => ['required', 'regex:/^\d{1,14}(?:\.\d{1,10})?$/', 'numeric', 'gt:0'], 'rates.*.face_value' => ['required', 'integer', 'between:1,1000000']])->validate();
        DB::transaction(function () use ($org, $actor, $data): void {
            Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
            $currencies = DB::table('organization_currencies')->where('organization_id', $org->id)->get();
            $codes = array_column($data['rates'], 'code');
            if (count($codes) !== $currencies->count() || array_diff($currencies->pluck('code')->all(), $codes)) {
                $this->fail('rates', 'Supply one rate for every organization currency.');
            }
            $base = $currencies->firstWhere('code', $data['base_code']);
            $baseRate = null;
            foreach ($data['rates'] as $rate) {
                if ($rate['code'] === $data['base_code']) {
                    $baseRate = $rate;
                    break;
                }
            }
            if (! $base || ! $baseRate || ! $base->active || (float) $baseRate['exchange_rate'] !== 1.0 || (int) $baseRate['face_value'] !== 1) {
                $this->fail('base_code', 'Select an active currency with rate and face value one.');
            }
            foreach ($data['rates'] as $rate) {
                DB::table('organization_currencies')->where('organization_id', $org->id)->where('code', $rate['code'])->update(['exchange_rate' => $rate['exchange_rate'], 'face_value' => $rate['face_value'], 'is_base' => $rate['code'] === $data['base_code'], 'updated_at' => now()]);
            }
            app(RecordOrganizationAuditLog::class)->handle($org, $actor, 'configuration.currencies.rebased', null, ['base_code' => $data['base_code'], 'rates' => $data['rates']]);
        });
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
