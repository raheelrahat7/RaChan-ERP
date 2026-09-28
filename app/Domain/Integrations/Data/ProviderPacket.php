<?php

namespace App\Domain\Integrations\Data;

final readonly class ProviderPacket
{
    /** @param array<string,mixed> $payload */
    public function __construct(public string $capability, public int $organizationId, public string $operationKey, public array $payload) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return ['schema_version' => 1, 'capability' => $this->capability, 'organization_id' => $this->organizationId, 'operation_key' => $this->operationKey, 'payload' => $this->payload];
    }
}
