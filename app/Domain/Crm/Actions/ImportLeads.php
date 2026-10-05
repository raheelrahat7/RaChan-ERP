<?php

namespace App\Domain\Crm\Actions;

use App\Domain\Crm\Models\LeadImportBatch;
use App\Domain\Crm\Models\Pipeline;
use App\Domain\Crm\Services\LeadVisibility;
use App\Domain\Crm\Services\ParseLeadImportCsv;
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

    /** @param array<string, mixed> $settings */
    public function preview(Organization $org, User $actor, UploadedFile $file, array $settings = []): LeadImportBatch
    {
        Gate::forUser($actor)->authorize('manageCrm', $org);
        $parsed = app(ParseLeadImportCsv::class)->parse($file, $settings);

        return DB::transaction(function () use ($org, $actor, $parsed): LeadImportBatch {
            $batch = LeadImportBatch::create(['organization_id' => $org->id, 'user_id' => $actor->id,
                'headers' => $parsed['headers'], 'rows' => $parsed['rows'], 'source_settings' => $parsed['settings'],
                'source_hash' => $parsed['source_hash'], 'expires_at' => now()->addDay()]);
            $this->audit->handle($org, $actor, 'crm.lead_import.previewed', $batch, ['rows' => count($parsed['rows'])]);

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
            'assigned_to' => ['sometimes', 'nullable', 'integer'],
            'required_targets' => ['sometimes', 'array', 'max:100'],
            'required_targets.*' => ['string', 'max:100', 'distinct'],
        ])->validate();
        $mapping = array_filter($data['mapping'], fn ($target) => $target !== null && $target !== '');
        if (count($mapping) !== count(array_unique($mapping)) || array_diff(array_keys($mapping), $batch->headers) || array_diff(array_values($mapping), $this->targets($org, $actor))) {
            throw ValidationException::withMessages(['mapping' => 'Map each source column once to an available lead field.']);
        }
        if ((! in_array('first_name', $mapping, true) || ! in_array('last_name', $mapping, true)) && ! in_array('full_name', $mapping, true)) {
            throw ValidationException::withMessages(['mapping' => 'Map first and last names, or a full-name column.']);
        }
        $requiredTargets = $data['required_targets'] ?? [];
        if (array_diff($requiredTargets, array_values($mapping))) {
            throw ValidationException::withMessages(['required_targets' => 'Required import fields must be mapped to a source column.']);
        }
        $pipeline = Pipeline::where('organization_id', $org->id)->where('active', true)->findOrFail((int) $data['pipeline_id']);
        if (isset($data['assigned_to'])) {
            app(LeadVisibility::class)->assigneeFilter($org, $actor, (int) $data['assigned_to']);
        }
        $customFields = collect($this->fields->visible($org, $actor, true))->keyBy('key');

        return DB::transaction(function () use ($org, $actor, $batch, $mapping, $data, $pipeline, $requiredTargets, $customFields): LeadImportBatch {
            $batch = LeadImportBatch::where('organization_id', $org->id)->where('user_id', $actor->id)->lockForUpdate()->findOrFail($batch->id);
            if ($batch->committed_at) {
                return $batch;
            }
            if ($batch->expires_at->isPast()) {
                throw ValidationException::withMessages(['batch' => 'Preview expired. Upload the file again.']);
            }
            Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
            $created = 0;
            $skipped = 0;
            $errors = [];
            $seen = [];
            $rowOffset = ($batch->source_settings['has_header'] ?? true) ? 2 : 1;
            foreach ($batch->rows as $index => $row) {
                $missing = [];
                foreach ($mapping as $header => $target) {
                    if (in_array($target, $requiredTargets, true) && trim((string) ($row[$header] ?? '')) === '') {
                        $missing[] = $header;
                    }
                }
                if ($missing !== []) {
                    $errors[] = ['row' => $batch->source_settings['row_numbers'][$index] ?? $index + $rowOffset, 'messages' => ['Required source fields are blank: '.implode(', ', $missing).'.']];

                    continue;
                }
                $leadData = ['pipeline_id' => $pipeline->id];
                if (isset($data['assigned_to'])) {
                    $leadData['assigned_to'] = (int) $data['assigned_to'];
                }
                $custom = [];
                foreach ($mapping as $header => $target) {
                    $cell = $row[$header] === '' ? null : $row[$header];
                    if (str_starts_with($target, 'custom:')) {
                        $key = substr($target, 7);
                        $type = $customFields->get($key)?->type;
                        $custom[$key] = match ($type) {
                            'multi_select' => $cell === null ? null : array_map('trim', explode('|', $cell)),
                            'checkbox' => $cell === null ? null : match (mb_strtolower($cell)) {
                                'yes', 'true', '1' => true,
                                'no', 'false', '0' => false,
                                default => $cell,
                            },
                            default => $cell,
                        };
                    } else {
                        $leadData[$target] = $cell;
                    }
                }
                if (! empty($leadData['full_name'])) {
                    $parts = preg_split('/\s+/u', trim($leadData['full_name']), 2) ?: [];
                    if (($batch->source_settings['name_format'] ?? 'first_last') === 'last_first') {
                        $parts = array_reverse($parts);
                    }
                    $leadData['first_name'] ??= $parts[0] ?? null;
                    $leadData['last_name'] ??= $parts[1] ?? ($parts[0] ?? null);
                }
                unset($leadData['full_name']);
                $leadData['custom_fields'] = $custom;
                $validation = Validator::make($leadData, ['first_name' => ['required', 'string', 'max:100'], 'last_name' => ['required', 'string', 'max:100'], 'email' => ['nullable', 'email', 'max:255'], 'phone' => ['nullable', 'string', 'max:50'], 'company' => ['nullable', 'string', 'max:255'], 'city' => ['nullable', 'string', 'max:255'], 'source' => ['nullable', 'string', 'max:100'], 'notes' => ['nullable', 'string', 'max:5000'], 'project_name' => ['nullable', 'string', 'max:255'], 'campaign_name' => ['nullable', 'string', 'max:255']]);
                if ($validation->fails()) {
                    $errors[] = ['row' => $batch->source_settings['row_numbers'][$index] ?? $index + $rowOffset, 'messages' => $validation->errors()->all()];

                    continue;
                }
                $email = mb_strtolower(trim((string) ($leadData['email'] ?? '')));
                $phone = preg_replace('/\D+/', '', (string) ($leadData['phone'] ?? ''));
                $keys = array_filter([$email !== '' ? 'email:'.$email : null, $phone !== '' ? 'phone:'.$phone : null]);
                $duplicate = $keys !== [] && (array_intersect($keys, array_keys($seen)) !== [] || CrmLead::where('organization_id', $org->id)
                    ->where(function ($query) use ($email, $phone): void {
                        if ($email !== '') {
                            $query->orWhereRaw('LOWER(TRIM(email)) = ?', [$email]);
                        }
                        if ($phone !== '') {
                            $query->orWhereRaw("REGEXP_REPLACE(phone, '[^0-9]', '') = ?", [$phone]);
                        }
                    })->exists());
                if ($data['duplicate_mode'] === 'skip' && $duplicate) {
                    $skipped++;

                    continue;
                }
                try {
                    $this->leads->create($org, $actor, $leadData);
                    $created++;
                    foreach ($keys as $key) {
                        $seen[$key] = true;
                    }
                } catch (ValidationException $exception) {
                    $errors[] = ['row' => $batch->source_settings['row_numbers'][$index] ?? $index + $rowOffset, 'messages' => $exception->errors()];
                }
            }
            $batch->update(['summary' => ['created' => $created, 'skipped_duplicates' => $skipped, 'failed' => count($errors)], 'errors' => $errors, 'committed_at' => now()]);
            $this->audit->handle($org, $actor, 'crm.lead_import.committed', $batch, ['created' => $created, 'skipped_duplicates' => $skipped, 'failed' => count($errors)]);

            return $batch;
        });
    }
}
