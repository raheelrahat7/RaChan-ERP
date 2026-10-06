<?php

namespace App\Domain\Crm\Actions;

use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Models\CrmAccount;
use App\Models\CrmContact;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ManageParties
{
    public function __construct(private ManageRecordFields $fields, private RecordOrganizationAuditLog $audit) {}

    /** @param array<string, mixed> $input */
    public function updateContact(Organization $org, User $actor, CrmContact $contact, array $input): CrmContact
    {
        $data = Validator::make($input, [
            'expected_version' => ['required', 'integer', 'min:1'],
            'first_name' => ['sometimes', 'required', 'string', 'max:100'],
            'last_name' => ['sometimes', 'required', 'string', 'max:100'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'account_id' => ['sometimes', 'nullable', 'integer'],
            'custom_fields' => ['sometimes', 'array'],
        ])->validate();

        return DB::transaction(function () use ($org, $actor, $contact, $data): CrmContact {
            $contact = CrmContact::where('organization_id', $org->id)->lockForUpdate()->findOrFail($contact->id);
            $this->checkVersion($contact->version, $data['expected_version']);
            if (array_key_exists('account_id', $data) && $data['account_id'] !== null && ! CrmAccount::where('organization_id', $org->id)->whereKey($data['account_id'])->exists()) {
                throw ValidationException::withMessages(['account_id' => 'Select a company in this organization.']);
            }
            if (array_key_exists('custom_fields', $data)) {
                $this->fields->write($org, $actor, $contact, $data['custom_fields']);
            }
            $contact->fill(collect($data)->only(['first_name', 'last_name', 'email', 'phone', 'account_id'])->all());
            $contact->version++;
            $contact->save();
            $this->audit->handle($org, $actor, 'crm.contact.updated', $contact);

            return $contact->refresh();
        });
    }

    /** @param array<string, mixed> $input */
    public function updateCompany(Organization $org, User $actor, CrmAccount $company, array $input): CrmAccount
    {
        $data = Validator::make($input, [
            'expected_version' => ['required', 'integer', 'min:1'],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'website' => ['sometimes', 'nullable', 'url', 'max:255'],
            'custom_fields' => ['sometimes', 'array'],
        ])->validate();

        return DB::transaction(function () use ($org, $actor, $company, $data): CrmAccount {
            $company = CrmAccount::where('organization_id', $org->id)->lockForUpdate()->findOrFail($company->id);
            $this->checkVersion($company->version, $data['expected_version']);
            if (array_key_exists('custom_fields', $data)) {
                $this->fields->write($org, $actor, $company, $data['custom_fields']);
            }
            $company->fill(collect($data)->only(['name', 'email', 'phone', 'website'])->all());
            $company->version++;
            $company->save();
            $this->audit->handle($org, $actor, 'crm.company.updated', $company);

            return $company->refresh();
        });
    }

    private function checkVersion(int $actual, int $expected): void
    {
        if ($actual !== $expected) {
            throw ValidationException::withMessages(['expected_version' => 'This record changed. Reload it and try again.']);
        }
    }
}
