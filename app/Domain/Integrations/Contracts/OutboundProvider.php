<?php

namespace App\Domain\Integrations\Contracts;

use App\Domain\Integrations\Data\ProviderPacket;

interface OutboundProvider
{
    /** Validate locally without performing a provider side effect.
     * @return array{status:string,sha256:string,packet:array<string,mixed>} */
    public function validate(ProviderPacket $packet): array;

    /** A selected provider must implement authenticated transport and idempotent receipts.
     * @return array{provider_reference:string,status:string} */
    public function deliver(ProviderPacket $packet): array;
}
