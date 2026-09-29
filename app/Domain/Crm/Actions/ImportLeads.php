<?php

namespace App\Domain\Crm\Actions;

use App\Domain\Crm\Models\LeadImportBatch;
use App\Domain\Crm\Models\Pipeline;
use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Models\CrmLead;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ImportLeads
{
    private const COLUMNS = ['first_name', 'last_name', 'full_name', 'email', 'phone', 'company', 'city', 'source', 'notes', 'project_name', 'campaign_name'];

    public function __construct(private ManageCustomFields $fields, private ManageLeadPipeline $leads, private RecordOrganizationAuditLog $audit) {}

    public function preview(Organization $org, User $actor, UploadedFile $file): LeadImportBatch
    {
        Gate::forUser($actor)->authorize('manageCrm', $org);
        Validator::make(['file' => $file], ['file' => ['required', 'file', 'max:2048', 'extensions:csv,txt']])->validate();
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
            $headers = fgetcsv($stream, null, ',', '"', '');
            if (! $headers || count($headers) > 100 || count($headers) !== count(array_unique($headers))) {
                throw ValidationException::withMessages(['file' => 'Use one header row with distinct column names (100 maximum).']);
            }
            $headers = array_map(fn ($header) => trim((string) $header), $headers);
            if (in_array('', $headers, true) || count($headers) !== count(array_unique($headers))) {
                throw ValidationException::withMessages(['file' => 'Every column needs a unique header.']);
            }
            $rows = [];
            while (($cells = fgetcsv($stream, null, ',', '"', '')) !== false) {
                if ($cells === [null]) {
                    continue;
                }
                if (count($rows) >= 1000 || count($cells) !== count($headers)) {
                    throw ValidationException::withMessages(['file' => 'Use at most 1,000 rows with the same number of columns as the header.']);
                }
                $rows[] = array_combine($headers, array_map(fn ($cell) => trim((string) $cell), $cells));
            }
            if ($rows === []) {
                throw ValidationException::withMessages(['file' => 'The CSV has no data rows.']);
            }
        } finally {
            fclose($stream);
        }

        return DB::transaction(function () use ($org, $actor, $headers, $rows, $raw): LeadImportBatch {
            $batch = LeadImportBatch::create(['organization_id' => $org->id, 'user_id' => $actor->id, 'headers' => $headers, 'rows' => $rows, 'source_hash' => hash('sha256', $raw), 'expires_at' => now()->addDay()]);
            $this->audit->handle($org, $actor, 'crm.lead_import.previewed', $batch, ['rows' => count($rows)]);

            return $batch;
        });
    }

    /** @return list<string> */
    public function targets(Organization $org, User $actor): array
    {
        return [...self::COLUMNS, ...array_map(fn ($field) => 'custom:'.$field->key, $this->fields->visible($org, $actor, true))];
    }

    /** @param array<string,mixed> $input */
    public function commit(Organization $org, User $actor, LeadImportBatch $batch, array $input): LeadImportBatch
    {
        Gate::forUser($actor)->authorize('manageCrm', $org);
        abort_unless($batch->organization_id === $org->id && $batch->user_id === $actor->id, 404);
        $data = Validator::make($input, [
            'mapping' => ['required', 'array'], 'mapping.*' => ['nullable', 'string', 'max:100'],
            'duplicate_mode' => ['required', 'in:skip,allow'],
            'pipeline_id' => ['required', 'integer'],
        ])->validate();
        $mapping = array_filter($data['mapping'], fn ($target) => $target !== null && $target !== '');
        if (count($mapping) !== count(array_unique($mapping)) || array_diff(array_keys($mapping), $batch->headers) || array_diff(array_values($mapping), $this->targets($org, $actor))) {
            throw ValidationException::withMessages(['mapping' => 'Map each source column once to an available lead field.']);
        }
        if ((! in_array('first_name', $mapping, true) || ! in_array('last_name', $mapping, true)) && ! in_array('full_name', $mapping, true)) {
            throw ValidationException::withMessages(['mapping' => 'Map first and last names, or a full-name column.']);
        }
        $pipeline = Pipeline::where('organization_id', $org->id)->where('active', true)->findOrFail((int) $data['pipeline_id']);

        return DB::transaction(function () use ($org, $actor, $batch, $mapping, $data, $pipeline): LeadImportBatch {
            $batch = LeadImportBatch::where('organization_id', $org->id)->where('user_id', $actor->id)->lockForUpdate()->findOrFail($batch->id);
            if ($batch->committed_at) {
                return $batch;
            }
            if ($batch->expires_at->isPast()) {
                throw ValidationException::withMessages(['batch' => 'Preview expired. Upload the file again.']);
            }
            $created = 0;
            $skipped = 0;
            $errors = [];
            $seen = [];
            foreach ($batch->rows as $index => $row) {
                $leadData = ['pipeline_id' => $pipeline->id];
                $custom = [];
                foreach ($mapping as $header => $target) {
                    if (str_starts_with($target, 'custom:')) {
                        $custom[substr($target, 7)] = $row[$header] ?: null;
                    } else {
                        $leadData[$target] = $row[$header] ?: null;
                    }
                }
                if (! empty($leadData['full_name'])) {
                    $parts = preg_split('/\s+/u', trim($leadData['full_name']), 2) ?: [];
                    $leadData['first_name'] ??= $parts[0] ?? null;
                    $leadData['last_name'] ??= $parts[1] ?? ($parts[0] ?? null);
                }
                unset($leadData['full_name']);
                $leadData['custom_fields'] = $custom;
                $validation = Validator::make($leadData, ['first_name' => ['required', 'string', 'max:100'], 'last_name' => ['required', 'string', 'max:100'], 'email' => ['nullable', 'email', 'max:255'], 'phone' => ['nullable', 'string', 'max:50'], 'company' => ['nullable', 'string', 'max:255'], 'city' => ['nullable', 'string', 'max:255'], 'source' => ['nullable', 'string', 'max:100'], 'notes' => ['nullable', 'string', 'max:5000'], 'project_name' => ['nullable', 'string', 'max:255'], 'campaign_name' => ['nullable', 'string', 'max:255']]);
                if ($validation->fails()) {
                    $errors[] = ['row' => $index + 2, 'messages' => $validation->errors()->all()];

                    continue;
                }
                $email = mb_strtolower(trim((string) ($leadData['email'] ?? '')));
                $phone = preg_replace('/\D+/', '', (string) ($leadData['phone'] ?? ''));
                $key = $email !== '' ? 'email:'.$email : ($phone !== '' ? 'phone:'.$phone : null);
                if ($data['duplicate_mode'] === 'skip' && $key !== null && (isset($seen[$key]) || CrmLead::where('organization_id', $org->id)->where($email !== '' ? 'email' : 'phone', $email !== '' ? $leadData['email'] : $leadData['phone'])->exists())) {
                    $skipped++;

                    continue;
                }
                if ($key !== null) {
                    $seen[$key] = true;
                }
                try {
                    $this->leads->create($org, $actor, $leadData);
                    $created++;
                } catch (ValidationException $exception) {
                    $errors[] = ['row' => $index + 2, 'messages' => $exception->errors()];
                }
            }
            $batch->update(['summary' => ['created' => $created, 'skipped_duplicates' => $skipped, 'failed' => count($errors)], 'errors' => $errors, 'committed_at' => now()]);
            $this->audit->handle($org, $actor, 'crm.lead_import.committed', $batch, ['created' => $created, 'skipped_duplicates' => $skipped, 'failed' => count($errors)]);

            return $batch;
        });
    }
}
