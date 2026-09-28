<?php

namespace App\Domain\Integrations\Services;

use App\Domain\Integrations\Contracts\SingleSignOnProvider;
use Illuminate\Support\Facades\Validator;
use LogicException;

class LocalSingleSignOnProvider implements SingleSignOnProvider
{
    public function validateConfiguration(array $configuration): array
    {
        return array_values(Validator::make($configuration, ['issuer' => ['required', 'url:https'], 'client_id' => ['required', 'string', 'max:255'], 'redirect_uri' => ['required', 'url:https']])->errors()->all());
    }

    public function authorizationUrl(string $state, string $nonce, string $challenge): string
    {
        throw new LogicException('Select and configure an SSO provider before enabling authentication.');
    }

    public function verifiedIdentity(string $code, string $state, string $nonce, string $verifier): array
    {
        throw new LogicException('Local validation does not authenticate an external identity.');
    }
}
