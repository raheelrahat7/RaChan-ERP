<?php

namespace App\Domain\Integrations\Services;

use App\Domain\Integrations\Contracts\OutboundProvider;
use App\Domain\Integrations\Data\ProviderPacket;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use LogicException;

class LocalOutboundProvider implements OutboundProvider
{
    public const CAPABILITIES = ['payment', 'accounting_export', 'email', 'whatsapp', 'property_portal', 'signature'];

    public function validate(ProviderPacket $packet): array
    {
        Validator::make(['capability' => $packet->capability, 'organization_id' => $packet->organizationId, 'operation_key' => $packet->operationKey], ['capability' => ['required', Rule::in(self::CAPABILITIES)], 'organization_id' => ['required', 'integer', 'min:1'], 'operation_key' => ['required', 'uuid']])->validate();
        $rules = match ($packet->capability) {
            'payment' => ['invoice_id' => ['required', 'integer', 'min:1'], 'reference' => ['required', 'string', 'max:100'], 'amount' => ['required', 'regex:/^[0-9]{1,12}\\.[0-9]{2}$/D'], 'currency' => ['required', 'regex:/^[A-Z]{3}$/D']],
            'accounting_export' => ['reference' => ['required', 'string', 'max:100'], 'currency' => ['required', 'regex:/^[A-Z]{3}$/D'], 'posted_on' => ['required', 'date_format:Y-m-d'], 'lines' => ['required', 'array', 'min:2', 'max:500'], 'lines.*' => ['required', 'array:account_code,debit,credit'], 'lines.*.account_code' => ['required', 'string', 'max:50'], 'lines.*.debit' => ['required', 'regex:/^[0-9]{1,12}\\.[0-9]{2}$/D'], 'lines.*.credit' => ['required', 'regex:/^[0-9]{1,12}\\.[0-9]{2}$/D']],
            'email' => ['to' => ['required', 'email', 'max:255'], 'subject' => ['required', 'string', 'max:255'], 'text' => ['required', 'string', 'max:10000']],
            'whatsapp' => ['to' => ['required', 'regex:/^\\+[1-9][0-9]{7,14}$/D'], 'template' => ['required', 'regex:/^[a-z][a-z0-9_]{0,99}$/D'], 'language' => ['required', 'in:en,ar'], 'parameters' => ['present', 'array', 'max:20'], 'parameters.*' => ['required', 'string', 'max:500']],
            'property_portal' => ['listing_id' => ['required', 'integer', 'min:1'], 'reference' => ['required', 'string', 'max:100'], 'purpose' => ['required', 'in:rent,sale'], 'price' => ['required', 'regex:/^[0-9]{1,12}\\.[0-9]{2}$/D'], 'currency' => ['required', 'regex:/^[A-Z]{3}$/D'], 'title' => ['required', 'string', 'max:255']],
            'signature' => ['document_id' => ['required', 'integer', 'min:1'], 'version_number' => ['required', 'integer', 'min:1'], 'sha256' => ['required', 'regex:/^[a-f0-9]{64}$/D'], 'signers' => ['required', 'array', 'min:1', 'max:10'], 'signers.*' => ['required', 'distinct', 'email', 'max:255']],
            default => throw ValidationException::withMessages(['capability' => 'Unsupported provider capability.']),
        };
        $allowed = array_filter(array_keys($rules), fn ($key): bool => ! str_contains($key, '.'));
        if (array_diff(array_keys($packet->payload), $allowed) !== []) {
            throw ValidationException::withMessages(['payload' => 'Unknown fields are not accepted. Do not include provider credentials or access tokens.']);
        }
        Validator::make($packet->payload, $rules)->validate();
        if ($packet->capability === 'payment' && $this->cents($packet->payload['amount']) === 0) {
            throw ValidationException::withMessages(['amount' => 'Payment amount must be positive.']);
        }
        if ($packet->capability === 'accounting_export') {
            $debit = 0;
            $credit = 0;
            foreach ($packet->payload['lines'] as $line) {
                $d = $this->cents($line['debit']);
                $c = $this->cents($line['credit']);
                if (($d > 0) == ($c > 0)) {
                    throw ValidationException::withMessages(['lines' => 'Each line must contain exactly one positive debit or credit.']);
                }$debit += $d;
                $credit += $c;
            }if ($debit !== $credit) {
                throw ValidationException::withMessages(['lines' => 'Journal export must balance.']);
            }
        }
        $array = $packet->toArray();
        $canonical = $this->canonical($array);

        return ['status' => 'local_validated', 'sha256' => hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)), 'packet' => $array];
    }

    private function cents(string $amount): int
    {
        return (int) str_replace('.', '', $amount);
    }

    /** @param array<mixed> $input
     * @return array<mixed> */
    private function canonical(array $input): array
    {
        if (! array_is_list($input)) {
            ksort($input);
        }foreach ($input as $key => $value) {
            if (is_array($value)) {
                $input[$key] = $this->canonical($value);
            }
        }

        return $input;
    }

    public function deliver(ProviderPacket $packet): array
    {
        throw new LogicException('Local adapters validate only. Select and configure a provider before delivery.');
    }
}
