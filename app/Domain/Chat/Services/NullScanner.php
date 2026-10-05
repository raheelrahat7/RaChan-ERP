<?php

namespace App\Domain\Chat\Services;

/** Used only when scanning is explicitly switched off (CHAT_VIRUS_SCAN=off), for local development. */
class NullScanner implements VirusScanner
{
    public function scan(string $path): ScanResult
    {
        return new ScanResult(true);
    }
}
