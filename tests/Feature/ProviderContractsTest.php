<?php

namespace Tests\Feature;

use App\Domain\Integrations\Data\ProviderPacket;
use App\Domain\Integrations\Services\LocalOutboundProvider;
use App\Domain\Integrations\Services\LocalSingleSignOnProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ProviderContractsTest extends TestCase
{
    public function test_local_adapters_validate_deterministically_but_cannot_deliver(): void
    {
        $provider = app(LocalOutboundProvider::class);
        $key = (string) Str::uuid();
        $payload = ['invoice_id' => 1, 'reference' => 'INV-1', 'amount' => '25.00', 'currency' => 'AED'];
        $packet = new ProviderPacket('payment', 1, $key, $payload);
        $validated = $provider->validate($packet);
        $this->assertSame('local_validated', $validated['status']);
        $this->assertSame($validated['sha256'], $provider->validate(new ProviderPacket('payment', 1, $key, array_reverse($payload, true)))['sha256']);
        $this->expectException(\LogicException::class);
        $provider->deliver($packet);
    }

    public function test_accounting_export_rejects_unbalanced_lines_and_unknown_secret_fields(): void
    {
        $provider = app(LocalOutboundProvider::class);
        $payload = ['reference' => 'J-1', 'currency' => 'AED', 'posted_on' => '2026-09-27', 'lines' => [['account_code' => '1190', 'debit' => '25.00', 'credit' => '0.00'], ['account_code' => '2100', 'debit' => '0.00', 'credit' => '25.00']]];
        $this->assertSame('local_validated', $provider->validate(new ProviderPacket('accounting_export', 1, (string) Str::uuid(), $payload))['status']);
        $payload['lines'][1]['credit'] = '24.00';
        try {
            $provider->validate(new ProviderPacket('accounting_export', 1, (string) Str::uuid(), $payload));
            $this->fail('Unbalanced export accepted');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('lines', $error->errors());
        }
        $this->expectException(ValidationException::class);
        $provider->validate(new ProviderPacket('email', 1, (string) Str::uuid(), ['to' => 'sample@example.test', 'subject' => 'Hello', 'text' => 'Sample', 'access_token' => 'must-not-appear']));
    }

    public function test_local_signature_and_sso_validation_cannot_claim_an_external_signature_or_identity(): void
    {
        $provider = app(LocalOutboundProvider::class);
        $validated = $provider->validate(new ProviderPacket('signature', 1, (string) Str::uuid(), ['document_id' => 1, 'version_number' => 2, 'sha256' => str_repeat('a', 64), 'signers' => ['sample@example.test']]));
        $this->assertSame('local_validated', $validated['status']);
        $this->assertArrayNotHasKey('signed_at', $validated);
        $sso = app(LocalSingleSignOnProvider::class);
        $this->assertSame([], $sso->validateConfiguration(['issuer' => 'https://identity.example.test', 'client_id' => 'local', 'redirect_uri' => 'https://erp.example.test/callback']));
        $this->assertNotEmpty($sso->validateConfiguration(['issuer' => 'http://unsafe.example.test', 'client_id' => 'local', 'redirect_uri' => 'http://erp.example.test/callback']));
        $this->expectException(\LogicException::class);
        $sso->verifiedIdentity('code', 'state', 'nonce', 'verifier');
    }
}
