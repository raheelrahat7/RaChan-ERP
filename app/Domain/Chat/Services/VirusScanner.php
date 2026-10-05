<?php

namespace App\Domain\Chat\Services;

interface VirusScanner
{
    /** @throws ScannerUnavailable when the scan could not be completed; callers must not treat that as clean. */
    public function scan(string $path): ScanResult;
}
