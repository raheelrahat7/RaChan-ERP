<?php

namespace App\Domain\Chat\Services;

final class ScanResult
{
    public function __construct(public readonly bool $clean, public readonly ?string $signature = null) {}
}
