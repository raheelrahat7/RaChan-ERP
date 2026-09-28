<?php

namespace App\Domain\Inventory\Actions;

use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Domain\Inventory\Models\InventoryImportBatch;
use App\Models\Organization;
use App\Models\Property;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ImportInventory
{
    private const HEADERS = ['properties' => ['name', 'type', 'city', 'address_line_1'], 'units' => ['property_id', 'number', 'type', 'status', 'area', 'asking_price']];

    public function __construct(private RecordOrganizationAuditLog $audit) {}

    public function preview(Organization $org, User $actor, string $kind, UploadedFile $file): InventoryImportBatch
    {
        Gate::forUser($actor)->authorize('manageInventory', $org);
        Validator::make(['kind' => $kind, 'file' => $file], ['kind' => ['required', Rule::in(array_keys(self::HEADERS))], 'file' => ['required', 'file', 'max:1024', 'extensions:csv']])->validate();
        $raw = file_get_contents($file->getRealPath());
        if ($raw === false || ! mb_check_encoding($raw, 'UTF-8') || str_contains($raw, "\0")) {
            throw ValidationException::withMessages(['file' => 'Upload a UTF-8 CSV without binary content.']);
        }
        $stream = fopen('php://temp', 'w+');
        if ($stream === false) {
            throw new \RuntimeException('Could not create import buffer.');
        }
        try {
            fwrite($stream, preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw);
            rewind($stream);
            $header = fgetcsv($stream, null, ',', '"', '');
            if ($header !== self::HEADERS[$kind]) {
                throw ValidationException::withMessages(['file' => 'Required header: '.implode(',', self::HEADERS[$kind])]);
            }
            $rows = [];
            $errors = [];
            $line = 1;
            $seen = [];
            while (($cells = fgetcsv($stream, null, ',', '"', '')) !== false) {
                $line++;
                if ($cells === [null]) {
                    continue;
                }if (count($rows) >= 500) {
                    throw ValidationException::withMessages(['file' => 'Import at most 500 rows at a time.']);
                }
                if (count($cells) !== count($header)) {
                    throw ValidationException::withMessages(['file' => 'Row '.$line.' does not match the header column count.']);
                }
                $row = array_combine($header, array_map(fn ($v): string => trim((string) $v), $cells));
                $rows[] = $row;
                $messages = $this->errors($org, $kind, $row);
                $key = mb_strtolower($kind === 'properties' ? $row['name'] : $row['property_id'].':'.$row['number']);
                if (isset($seen[$key])) {
                    $messages[] = 'Duplicate record in this file.';
                }$seen[$key] = true;
                if ($messages !== []) {
                    $errors[] = ['row' => $line, 'messages' => $messages];
                }
            }
            if ($rows === []) {
                throw ValidationException::withMessages(['file' => 'The file has no data rows.']);
            }
        } finally {
            fclose($stream);
        }

        return DB::transaction(function () use ($org, $actor, $kind, $rows, $errors, $raw): InventoryImportBatch {
            Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
            if (InventoryImportBatch::where('organization_id', $org->id)->where('user_id', $actor->id)->whereNull('committed_at')->where('expires_at', '>', now())->count() >= 10) {
                throw ValidationException::withMessages(['file' => 'At most ten uncommitted previews may be active.']);
            }
            $batch = InventoryImportBatch::create(['organization_id' => $org->id, 'user_id' => $actor->id, 'kind' => $kind, 'rows' => $rows, 'errors' => $errors, 'source_hash' => hash('sha256', $raw), 'expires_at' => now()->addDay()]);
            $this->audit->handle($org, $actor, 'inventory.import.previewed', $batch, ['kind' => $kind, 'rows' => count($rows), 'invalid_rows' => count($errors)]);

            return $batch;
        });
    }

    /** @param array<string,string> $row
     * @return list<string> */
    private function errors(Organization $org, string $kind, array $row): array
    {
        $rules = $kind === 'properties' ? ['name' => ['required', 'string', 'max:255'], 'type' => ['required', 'in:residential,commercial,mixed_use,land'], 'city' => ['nullable', 'string', 'max:100'], 'address_line_1' => ['nullable', 'string', 'max:255']] : ['property_id' => ['required', 'integer', Rule::exists('properties', 'id')->where('organization_id', $org->id)], 'number' => ['required', 'string', 'max:100'], 'type' => ['required', 'in:apartment,office,retail,warehouse,plot,other'], 'status' => ['required', 'in:available,unavailable'], 'area' => ['nullable', 'regex:/^\\d{1,8}(\\.\\d{1,2})?$/D'], 'asking_price' => ['nullable', 'regex:/^\\d{1,10}(\\.\\d{1,2})?$/D']];
        $input = array_map(fn ($v) => $v === '' ? null : $v, $row);
        $messages = Validator::make($input, $rules)->errors()->all();
        if ($kind === 'properties' && Property::where('organization_id', $org->id)->where('name', $row['name'])->exists()) {
            $messages[] = 'A property with this name already exists.';
        }
        if ($kind === 'units' && Unit::where('organization_id', $org->id)->where('property_id', $row['property_id'])->where('number', $row['number'])->exists()) {
            $messages[] = 'This property already has the unit number.';
        }

        return array_values($messages);
    }

    public function commit(Organization $org, User $actor, int $id): InventoryImportBatch
    {
        Gate::forUser($actor)->authorize('manageInventory', $org);

        return DB::transaction(function () use ($org, $actor, $id): InventoryImportBatch {
            Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
            $batch = InventoryImportBatch::where('organization_id', $org->id)->where('user_id', $actor->id)->lockForUpdate()->findOrFail($id);
            if ($batch->committed_at !== null) {
                return $batch;
            }
            if ($batch->expires_at->isPast()) {
                throw ValidationException::withMessages(['batch' => 'Preview expired. Upload the file again.']);
            }
            if ($batch->errors !== []) {
                throw ValidationException::withMessages(['batch' => 'Correct invalid rows and upload again. No records were imported.']);
            }
            foreach ($batch->rows as $index => $row) {
                $errors = $this->errors($org, $batch->kind, $row);
                if ($errors !== []) {
                    throw ValidationException::withMessages(['batch' => 'Row '.($index + 2).': '.implode(' ', $errors)]);
                }
            }
            $ids = [];
            foreach ($batch->rows as $row) {
                $input = array_map(fn ($v) => $v === '' ? null : $v, $row);
                $model = $batch->kind === 'properties' ? Property::create(['organization_id' => $org->id, ...$input]) : Unit::create(['organization_id' => $org->id, ...$input]);
                $ids[] = $model->id;
                $this->audit->handle($org, $actor, 'inventory.'.$batch->kind.'.imported', $model, ['batch_id' => $batch->id]);
            }
            $batch->update(['committed_at' => now(), 'created_ids' => $ids]);
            $this->audit->handle($org, $actor, 'inventory.import.committed', $batch, ['count' => count($ids)]);

            return $batch;
        });
    }
}
