<?php

namespace App\Domain\Integrations\Contracts;

interface SingleSignOnProvider
{
    /** @param array<string,string> $configuration
     * @return list<string> */
    public function validateConfiguration(array $configuration): array;

    public function authorizationUrl(string $state, string $nonce, string $challenge): string;

    /** @return array{issuer:string,subject:string,email:string,email_verified:bool} */
    public function verifiedIdentity(string $code, string $state, string $nonce, string $verifier): array;
}
